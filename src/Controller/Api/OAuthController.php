<?php

namespace App\Controller\Api;

use App\Account\AccountEmailSender;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Api\ProfileSlug;
use App\Api\UserResource;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\OAuth\OAuthProviderClient;
use App\OAuth\OAuthProviderException;
use App\Security\SessionAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class OAuthController extends AbstractController
{
    #[Route('/api/auth/oauth/providers', name: 'api_auth_oauth_providers', methods: ['GET'])]
    public function providers(Request $request, OAuthProviderClient $providers): JsonResponse
    {
        if (LocaleContext::fromRequest($request) === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        return new JsonResponse([
            'data' => [
                'google' => $providers->isEnabled('google'),
                'apple' => $providers->isEnabled('apple'),
            ],
        ]);
    }

    #[Route('/api/auth/oauth/{provider}/start', name: 'api_auth_oauth_start', methods: ['GET'])]
    public function start(
        string $provider,
        Request $request,
        OAuthProviderClient $providers,
        #[Autowire('%kernel.project_dir%/config/localized_routes.json')] string $localizedRoutesFile,
    ): RedirectResponse {
        $locale = LocaleContext::fromRequest($request) ?? 'bs';
        $mode = $request->query->getString('mode', 'login');
        if (!in_array($mode, ['login', 'register'], true)) {
            $mode = 'login';
        }
        $accountType = $request->query->getString('accountType');
        if (!in_array($accountType, ['creator', 'company'], true)) {
            $accountType = null;
        }
        if (!$providers->isEnabled($provider)) {
            return $this->accountRedirect($localizedRoutesFile, $locale, $mode, 'error');
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $request->getSession()->remove('wave_oauth_registration');
        $request->getSession()->set('wave_oauth_flow_'.$provider, [
            'state' => $state,
            'nonce' => $nonce,
            'locale' => $locale,
            'mode' => $mode,
            'accountType' => $accountType,
            'createdAt' => time(),
        ]);

        try {
            return new RedirectResponse($providers->authorizationUrl($provider, $state, $nonce));
        } catch (OAuthProviderException) {
            return $this->accountRedirect($localizedRoutesFile, $locale, $mode, 'error');
        }
    }

    #[Route('/api/auth/oauth/{provider}/callback', name: 'api_auth_oauth_callback', methods: ['GET', 'POST'])]
    public function callback(
        string $provider,
        Request $request,
        OAuthProviderClient $providers,
        EntityManagerInterface $entityManager,
        Security $security,
        LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/config/localized_routes.json')] string $localizedRoutesFile,
    ): RedirectResponse {
        $flowKey = 'wave_oauth_flow_'.$provider;
        $flow = $request->getSession()->get($flowKey);
        $parameters = $request->isMethod('POST') ? $request->request : $request->query;
        $state = $parameters->getString('state');
        $nonce = is_array($flow) && is_string($flow['nonce'] ?? null) ? $flow['nonce'] : null;
        if (!in_array($provider, ['google', 'apple'], true)
            || !is_array($flow)
            || !is_string($flow['state'] ?? null)
            || !hash_equals($flow['state'], $state)
            || !is_string($nonce)
            || !is_int($flow['createdAt'] ?? null)
            || $flow['createdAt'] < time() - 900
        ) {
            return $this->accountRedirect($localizedRoutesFile, $this->flowLocale($flow), $this->flowMode($flow), 'error');
        }
        $request->getSession()->remove($flowKey);

        $locale = $this->flowLocale($flow);
        $mode = $this->flowMode($flow);
        $code = $parameters->getString('code');
        if ($parameters->has('error') || $code === '' || mb_strlen($code) > 4096) {
            return $this->accountRedirect($localizedRoutesFile, $locale, $mode, 'error');
        }

        try {
            $identity = $providers->authenticateCode($provider, $code, $nonce);
        } catch (OAuthProviderException $exception) {
            $logger->warning('OAuth provider authentication failed.', [
                'provider' => $provider,
                'reason' => $exception->getMessage(),
            ]);

            return $this->accountRedirect($localizedRoutesFile, $locale, $mode, 'error');
        }

        if ($provider === 'apple' && $request->isMethod('POST')) {
            $appleUser = json_decode($parameters->getString('user'), true);
            if (is_array($appleUser) && is_array($appleUser['name'] ?? null)) {
                $givenName = $appleUser['name']['firstName'] ?? null;
                $familyName = $appleUser['name']['lastName'] ?? null;
                $identity['givenName'] = is_string($givenName) ? mb_substr(trim($givenName), 0, 60) : null;
                $identity['familyName'] = is_string($familyName) ? mb_substr(trim($familyName), 0, 60) : null;
            }
        }

        $identityRecord = $entityManager->getRepository(OAuthIdentity::class)->findOneBy([
            'provider' => $provider,
            'subject' => $identity['subject'],
        ]);
        if ($identityRecord instanceof OAuthIdentity) {
            $security->login($identityRecord->getUser(), SessionAuthenticator::class, 'main');

            return $this->accountRedirect($localizedRoutesFile, $locale, 'login', 'success');
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $identity['email']]);
        if ($user instanceof User) {
            $user->setEmailVerified(true);
            $entityManager->persist(new OAuthIdentity($user, $provider, $identity['subject']));
            $entityManager->flush();
            $security->login($user, SessionAuthenticator::class, 'main');

            return $this->accountRedirect($localizedRoutesFile, $locale, 'login', 'success');
        }

        $request->getSession()->set('wave_oauth_registration', [
            'provider' => $provider,
            'subject' => $identity['subject'],
            'email' => $identity['email'],
            'givenName' => $identity['givenName'],
            'familyName' => $identity['familyName'],
            'accountType' => $mode === 'register' ? ($flow['accountType'] ?? null) : null,
            'createdAt' => time(),
        ]);

        return $this->accountRedirect($localizedRoutesFile, $locale, 'register', 'complete');
    }

    #[Route('/api/auth/oauth/pending', name: 'api_auth_oauth_pending', methods: ['GET'])]
    public function pending(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $pending = $request->getSession()->get('wave_oauth_registration');
        if (!is_array($pending)
            || !is_int($pending['createdAt'] ?? null)
            || $pending['createdAt'] < time() - 900
            || !is_string($pending['email'] ?? null)
        ) {
            $request->getSession()->remove('wave_oauth_registration');

            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }

        return new JsonResponse([
            'data' => [
                'email' => $pending['email'],
                'firstName' => is_string($pending['givenName'] ?? null) ? $pending['givenName'] : '',
                'lastName' => is_string($pending['familyName'] ?? null) ? $pending['familyName'] : '',
                'accountType' => in_array($pending['accountType'] ?? null, ['creator', 'company'], true) ? $pending['accountType'] : 'creator',
            ],
        ]);
    }

    #[Route('/api/auth/oauth/complete', name: 'api_auth_oauth_complete', methods: ['POST'])]
    public function complete(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        AccountEmailSender $emailSender,
        #[Target('wave_register_ip')] RateLimiterFactoryInterface $registrationLimiter,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }

        $pending = $request->getSession()->get('wave_oauth_registration');
        if (!is_array($pending)
            || !in_array($pending['provider'] ?? null, ['google', 'apple'], true)
            || !is_string($pending['subject'] ?? null)
            || !is_string($pending['email'] ?? null)
            || !is_int($pending['createdAt'] ?? null)
            || $pending['createdAt'] < time() - 900
        ) {
            $request->getSession()->remove('wave_oauth_registration');

            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $data = JsonPayload::fromRequest($request);
        if ($data === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $type = $data['accountType'] ?? null;
        $phone = is_string($data['phone'] ?? null) ? trim($data['phone']) : '';
        $city = is_string($data['city'] ?? null) ? trim($data['city']) : '';
        $countryCode = is_string($data['country'] ?? null) ? strtoupper(trim($data['country'])) : '';
        if (!in_array($type, ['creator', 'company'], true)
            || $phone === ''
            || mb_strlen($phone) > 40
            || $city === ''
            || mb_strlen($city) > 70
            || preg_match('/^[A-Z]{2}$/', $countryCode) !== 1
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_registration', $locale)], 400);
        }

        if ($type === 'creator') {
            $firstName = is_string($data['firstName'] ?? null) ? trim($data['firstName']) : '';
            $lastName = is_string($data['lastName'] ?? null) ? trim($data['lastName']) : '';
            if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 60 || mb_strlen($lastName) > 60) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_registration', $locale)], 400);
            }
            $name = $firstName.' '.$lastName;
        } else {
            $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_registration', $locale)], 400);
        }

        $limit = $registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(
                ['error' => ApiMessages::get('rate_limited', $locale)],
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }
        if ($entityManager->getRepository(User::class)->findOneBy(['email' => $pending['email']]) instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('email_taken', $locale)], 409);
        }

        $user = new User($pending['email'], $type === 'creator' ? 'ROLE_CREATOR' : 'ROLE_COMPANY');
        $user->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(64))));
        $user->setPhone($phone);
        $user->setCity($city);
        $user->setCountryCode($countryCode);
        $user->setPreferredLocale($locale);
        $user->setEmailVerified(true);
        $user->setApproved(false);

        if ($type === 'creator') {
            $categories = $this->categoryList($data['categories'] ?? []);
            $location = is_string($data['location'] ?? null) ? trim($data['location']) : '';
            if ($categories === null || $location === '' || mb_strlen($location) > 120) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_registration', $locale)], 400);
            }
            $user->setCreator(new Creator(
                ProfileSlug::fromName($name),
                $name,
                $categories[0],
                $location,
                '',
                [],
                [],
                categories: $categories,
            ));
        } else {
            $industry = is_string($data['industry'] ?? null) ? trim($data['industry']) : '';
            if ($industry === '' || mb_strlen($industry) > 100) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_registration', $locale)], 400);
            }
            $user->setCompany(new Company(ProfileSlug::fromName($name), $name, $industry));
        }

        $entityManager->persist($user);
        $entityManager->persist(new OAuthIdentity($user, $pending['provider'], $pending['subject']));
        try {
            $emailSender->sendRegistrationReceived($user, $locale);
        } catch (TransportExceptionInterface) {
            return new JsonResponse(['error' => ApiMessages::get('registration_email_failed', $locale)], 503);
        }
        $entityManager->flush();
        $request->getSession()->remove('wave_oauth_registration');
        $security->login($user, SessionAuthenticator::class, 'main');

        return new JsonResponse([
            'data' => UserResource::fromEntity($user, $locale),
            'csrfToken' => $tokenManager->refreshToken('wave')->getValue(),
        ], 201);
    }

    private function accountRedirect(string $localizedRoutesFile, string $locale, string $mode, string $status): RedirectResponse
    {
        $routes = json_decode((string) file_get_contents($localizedRoutesFile), true, 32, JSON_THROW_ON_ERROR);
        $segment = is_array($routes) && is_array($routes[$locale] ?? null) && is_string($routes[$locale]['account'] ?? null)
            ? $routes[$locale]['account']
            : 'account';
        $prefix = $locale === 'bs' ? '' : '/'.$locale;

        return new RedirectResponse($prefix.'/'.$segment.'?'.http_build_query(['mode' => $mode, 'oauth' => $status]));
    }

    private function flowLocale(mixed $flow): string
    {
        return is_array($flow) && in_array($flow['locale'] ?? null, LocaleContext::SUPPORTED, true)
            ? $flow['locale']
            : 'bs';
    }

    private function flowMode(mixed $flow): string
    {
        return is_array($flow) && in_array($flow['mode'] ?? null, ['login', 'register'], true)
            ? $flow['mode']
            : 'login';
    }

    private function categoryList(mixed $value): ?array
    {
        if (!is_array($value) || $value === [] || count($value) > 5) {
            return null;
        }

        $categories = [];
        foreach ($value as $category) {
            if (!is_string($category)) {
                return null;
            }
            $category = trim($category);
            if ($category === '' || mb_strlen($category) > 80) {
                return null;
            }
            if (!in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        return $categories === [] ? null : $categories;
    }
}
