<?php

namespace App\Controller\Api;

use App\Account\AccountEmailSender;
use App\Account\UserActionTokenManager;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Api\ProfileSlug;
use App\Api\UserResource;
use App\Api\MarketplaceCategoryLabels;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Security\SessionAuthenticator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AuthController extends AbstractController
{
    #[Route('/api/auth/csrf', name: 'api_auth_csrf', methods: ['GET'])]
    public function csrf(Request $request, CsrfTokenManagerInterface $tokenManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        return new JsonResponse(['csrfToken' => $tokenManager->getToken('wave')->getValue()]);
    }

    #[Route('/api/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(Request $request, Security $security, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }

        return new JsonResponse(['data' => UserResource::fromEntity($user, $locale, MarketplaceCategoryLabels::forLocale($entityManager, $locale))]);
    }

    #[Route('/api/auth/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        UserActionTokenManager $actionTokens,
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
        $data = JsonPayload::fromRequest($request);
        if ($data === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $type = $data['accountType'] ?? null;
        $phone = is_string($data['phone'] ?? null) ? trim($data['phone']) : '';
        $city = is_string($data['city'] ?? null) ? trim($data['city']) : '';
        $countryCode = is_string($data['country'] ?? null) ? strtoupper(trim($data['country'])) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180 || mb_strlen($password) < 12 || mb_strlen($password) > 4096 || !in_array($type, ['creator', 'company'], true) || $phone === '' || mb_strlen($phone) > 40 || $city === '' || mb_strlen($city) > 70 || preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
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
        if ($rateLimitError = $this->rateLimitError($registrationLimiter->create($request->getClientIp() ?? 'unknown'), $locale)) {
            return $rateLimitError;
        }
        if ($entityManager->getRepository(User::class)->findOneBy(['email' => $email]) instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('email_taken', $locale)], 409);
        }

        $user = new User($email, $type === 'creator' ? 'ROLE_CREATOR' : 'ROLE_COMPANY');
        $user->setPassword($passwordHasher->hashPassword($user, $password));
        $user->setPhone($phone);
        $user->setCity($city);
        $user->setCountryCode($countryCode);
        $user->setPreferredLocale($locale);
        $user->setEmailVerified(false);
        $user->setApproved(false);
        if ($type === 'creator') {
            $category = is_string($data['category'] ?? null) ? trim($data['category']) : '';
            $categories = $this->categoryList($data['categories'] ?? [$category]);
            if ($categories === null) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_registration', $locale)], 400);
            }
            $category = $categories[0];
            $user->setCreator(new Creator(
                ProfileSlug::fromName($name),
                $name,
                $category,
                $city,
                '',
                [],
                [],
                categories: $categories,
            ));
        } else {
            $industries = $data['industries'] ?? [$data['industry'] ?? ''];
            if (!is_array($industries) || !array_is_list($industries) || count($industries) < 1 || count($industries) > 20
                || array_filter($industries, static fn ($value): bool => !is_string($value) || mb_strlen(trim($value)) < 2 || mb_strlen($value) > 100) !== []
            ) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_profile', $locale)], 400);
            }
            $industries = array_values(array_unique(array_map('trim', $industries)));
            $industry = $industries[0];
            if ($industry === '' || mb_strlen($industry) > 100) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_registration', $locale)], 400);
            }
            $company = new Company(ProfileSlug::fromName($name), $name, $industry);
            $company->setIndustries($industries);
            $user->setCompany($company);
        }

        $entityManager->persist($user);
        $entityManager->flush();
        $verificationToken = $actionTokens->issue($user, 'verify_email', new DateTimeImmutable('+24 hours'));
        $emailSender->sendVerification($user, $verificationToken, $locale);
        $security->login($user, SessionAuthenticator::class, 'main');

        return new JsonResponse([
            'data' => UserResource::fromEntity($user, $locale, MarketplaceCategoryLabels::forLocale($entityManager, $locale)),
            'csrfToken' => $tokenManager->refreshToken('wave')->getValue(),
        ], 201);
    }

    #[Route('/api/auth/verify-email', name: 'api_auth_verify_email', methods: ['POST'])]
    public function verifyEmail(
        Request $request,
        UserActionTokenManager $actionTokens,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $data = JsonPayload::fromRequest($request);
        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        if ($data === null || !$actionTokens->consume($token, 'verify_email', static function (User $user): void {
            $user->setEmailVerified(true);
        })) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_email_verification_link', $locale)], 400);
        }

        return new JsonResponse([
            'data' => ['emailVerified' => true],
            'message' => ApiMessages::get('email_verified', $locale),
        ]);
    }

    #[Route('/api/auth/verification-email', name: 'api_auth_verification_email', methods: ['POST'])]
    public function sendVerificationEmail(
        Request $request,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        UserActionTokenManager $actionTokens,
        AccountEmailSender $emailSender,
        #[Target('wave_verification_ip')] RateLimiterFactoryInterface $ipLimiter,
        #[Target('wave_verification_identity')] RateLimiterFactoryInterface $identityLimiter,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        $ipLimit = $this->rateLimitError($ipLimiter->create($request->getClientIp() ?? 'unknown'), $locale);
        $identityLimit = $this->rateLimitError($identityLimiter->create(hash('sha256', $user->getEmail())), $locale);
        if ($ipLimit !== null || $identityLimit !== null) {
            return $ipLimit ?? $identityLimit;
        }
        if (!$user->isEmailVerified()) {
            $token = $actionTokens->issue($user, 'verify_email', new DateTimeImmutable('+24 hours'));
            $emailSender->sendVerification($user, $token, $locale);
        }

        return new JsonResponse(['message' => ApiMessages::get('verification_email_sent', $locale)]);
    }

    #[Route('/api/auth/password-reset-requests', name: 'api_auth_password_reset_request', methods: ['POST'])]
    public function requestPasswordReset(
        Request $request,
        EntityManagerInterface $entityManager,
        CsrfTokenManagerInterface $tokenManager,
        UserActionTokenManager $actionTokens,
        AccountEmailSender $emailSender,
        #[Target('wave_password_reset_ip')] RateLimiterFactoryInterface $ipLimiter,
        #[Target('wave_password_reset_identity')] RateLimiterFactoryInterface $identityLimiter,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $data = JsonPayload::fromRequest($request);
        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';
        if ($data === null || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $ipLimit = $this->rateLimitError($ipLimiter->create($request->getClientIp() ?? 'unknown'), $locale);
        $identityLimit = $this->rateLimitError($identityLimiter->create(hash('sha256', $email)), $locale);
        if ($ipLimit !== null || $identityLimit !== null) {
            return $ipLimit ?? $identityLimit;
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user instanceof User) {
            $token = $actionTokens->issue($user, 'reset_password', new DateTimeImmutable('+1 hour'));
            $emailSender->sendPasswordReset($user, $token, $locale);
        }

        return new JsonResponse(['message' => ApiMessages::get('password_reset_requested', $locale)], 202);
    }

    #[Route('/api/auth/password-resets', name: 'api_auth_password_reset', methods: ['POST'])]
    public function resetPassword(
        Request $request,
        UserActionTokenManager $actionTokens,
        UserPasswordHasherInterface $passwordHasher,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $data = JsonPayload::fromRequest($request);
        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        if ($data === null || mb_strlen($password) < 12 || mb_strlen($password) > 4096) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_new_password', $locale)], 400);
        }
        $consumed = $actionTokens->consume($token, 'reset_password', static function (User $user) use ($passwordHasher, $password): void {
            $user->setPassword($passwordHasher->hashPassword($user, $password));
            $user->setEmailVerified(true);
        }, revokeAll: true);
        if (!$consumed) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_password_reset_link', $locale)], 400);
        }

        return new JsonResponse(['message' => ApiMessages::get('password_reset_completed', $locale)]);
    }

    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        #[Target('wave_login_ip')] RateLimiterFactoryInterface $ipLimiter,
        #[Target('wave_login_identity')] RateLimiterFactoryInterface $identityLimiter,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $data = JsonPayload::fromRequest($request);
        if ($data === null || !is_string($data['email'] ?? null) || !is_string($data['password'] ?? null)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $email = mb_strtolower(trim($data['email']));
        $ipLimit = $this->rateLimitError($ipLimiter->create($request->getClientIp() ?? 'unknown'), $locale);
        $identityLimit = $this->rateLimitError($identityLimiter->create(hash('sha256', $email)), $locale);
        if ($ipLimit !== null || $identityLimit !== null) {
            return $ipLimit ?? $identityLimit;
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User || !$passwordHasher->isPasswordValid($user, $data['password'])) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_credentials', $locale)], 401);
        }

        $security->login($user, SessionAuthenticator::class, 'main');

        return new JsonResponse([
            'data' => UserResource::fromEntity($user, $locale, MarketplaceCategoryLabels::forLocale($entityManager, $locale)),
            'csrfToken' => $tokenManager->refreshToken('wave')->getValue(),
        ]);
    }

    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(
        Request $request,
        Security $security,
        TokenStorageInterface $tokenStorage,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }

        $request->getSession()->invalidate();
        $tokenStorage->setToken(null);

        return new JsonResponse(['data' => ['loggedOut' => true]]);
    }

    private function rateLimitError(\Symfony\Component\RateLimiter\LimiterInterface $limiter, string $locale): ?JsonResponse
    {
        $limit = $limiter->consume();
        if ($limit->isAccepted()) {
            return null;
        }
        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        return new JsonResponse(
            ['error' => ApiMessages::get('rate_limited', $locale)],
            429,
            ['Retry-After' => (string) $retryAfter],
        );
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

        return $categories;
    }
}
