<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Account\AccountEmailSender;
use App\Account\AccountEmails;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Entity\UserEmail;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Security\SessionAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Settings → E-mail adrese and Načini prijave: extra sign-in addresses, the main address and linked Google/Apple accounts. */
final class AccountEmailController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountEmails $emails,
        private readonly AccountEmailSender $sender,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/me/sign-in', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        [$user, $locale] = $this->context($request, false);
        if ($user instanceof JsonResponse) return $user;

        return $this->ok($user);
    }

    #[Route('/api/me/emails', methods: ['POST'])]
    public function add(Request $request, #[Target('wave_verification_identity')] RateLimiterFactoryInterface $limiter): JsonResponse
    {
        [$user, $locale] = $this->context($request);
        if ($user instanceof JsonResponse) return $user;
        $data = JsonPayload::fromRequest($request) ?? [];
        $email = AccountEmails::normalize($data['email'] ?? null);
        if ($email === null) return $this->error('invalid_request', $locale, 400);
        if ($error = $this->passwordError($user, $data, $locale)) return $error;
        if ($email === $user->getEmail() || $this->extra($user, $email) instanceof UserEmail) return $this->error('email_already_yours', $locale, 409);
        if ($this->emails->owner($email, $user) instanceof User) return $this->error('email_belongs_to_other', $locale, 409);
        if (count($this->emails->extras($user)) >= AccountEmails::MAX_EXTRA) return $this->error('email_limit', $locale, 422);
        if (!$limiter->create('extra-email:'.$user->getId())->consume()->isAccepted()) return $this->error('rate_limited', $locale, 429);

        $extra = new UserEmail($user, $email);
        $token = $extra->issueVerifyToken();
        $this->em->persist($extra);
        $this->em->flush();
        $this->mail(fn () => $this->sender->sendExtraEmailVerification($user, $email, $token));
        $this->mail(fn () => $this->sender->sendSecurityNotice($user, 'email_added', $email));

        return $this->ok($user, sprintf(ApiMessages::get('email_added', $locale), $email), 201);
    }

    #[Route('/api/me/emails/{id}/resend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resend(int $id, Request $request, #[Target('wave_verification_identity')] RateLimiterFactoryInterface $limiter): JsonResponse
    {
        [$user, $locale] = $this->context($request);
        if ($user instanceof JsonResponse) return $user;
        $extra = $this->owned($user, $id);
        if (!$extra instanceof UserEmail || $extra->isVerified()) return $this->error('invalid_request', $locale, 404);
        if ($this->emails->owner($extra->getEmail(), $user) instanceof User) return $this->error('email_belongs_to_other', $locale, 409);
        if (!$limiter->create('extra-email:'.$user->getId())->consume()->isAccepted()) return $this->error('rate_limited', $locale, 429);
        $token = $extra->issueVerifyToken();
        $this->em->flush();
        $this->mail(fn () => $this->sender->sendExtraEmailVerification($user, $extra->getEmail(), $token));

        return $this->ok($user, ApiMessages::get('email_verification_resent', $locale));
    }

    #[Route('/api/auth/verify-extra-email', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->error('unsupported_language', 'bs', 400);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $token = (string) ((JsonPayload::fromRequest($request) ?? [])['token'] ?? '');
        $extra = preg_match('/^[a-f0-9]{64}$/D', $token) === 1
            ? $this->em->getRepository(UserEmail::class)->findOneBy(['verifyTokenHash' => hash('sha256', $token)]) : null;
        if (!$extra instanceof UserEmail || !$extra->isTokenValid($token) || $extra->getUser()->isDeleted()) {
            return $this->error('email_extra_link_invalid', $locale, 400);
        }
        // Someone else may have registered the address while the link waited in the inbox.
        if ($this->emails->owner($extra->getEmail(), $extra->getUser()) instanceof User) return $this->error('email_belongs_to_other', $locale, 409);
        $extra->markVerified();
        $this->em->flush();

        return new JsonResponse(['message' => ApiMessages::get('email_extra_verified', $locale)]);
    }

    #[Route('/api/me/emails/{id}/primary', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function makePrimary(int $id, Request $request): JsonResponse
    {
        [$user, $locale] = $this->context($request);
        if ($user instanceof JsonResponse) return $user;
        $extra = $this->owned($user, $id);
        if (!$extra instanceof UserEmail) return $this->error('invalid_request', $locale, 404);
        if (!$extra->isVerified()) return $this->error('email_primary_unverified', $locale, 409);
        if ($error = $this->passwordError($user, JsonPayload::fromRequest($request) ?? [], $locale)) return $error;
        $previous = $user->getEmail();
        $next = $extra->getEmail();
        $this->em->wrapInTransaction(function () use ($user, $extra, $previous, $next): void {
            $this->em->remove($extra);
            $this->em->flush();
            $user->changePrimaryEmail($next);
            $user->setEmailVerified(true);
            $this->em->persist(new UserEmail($user, $previous, true));
            $this->em->flush();
        });
        // The session is keyed by the main address: re-authenticate this one; other sessions sign in again.
        $this->security->login($user, SessionAuthenticator::class, 'main');
        $this->mail(fn () => $this->sender->sendSecurityNotice($user, 'primary_changed', $next, $previous));

        return $this->ok($user, sprintf(ApiMessages::get('email_primary_changed', $locale), $next), 200, ['csrfToken' => $this->csrf->refreshToken('wave')->getValue()]);
    }

    #[Route('/api/me/emails/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function remove(int $id, Request $request): JsonResponse
    {
        [$user, $locale] = $this->context($request);
        if ($user instanceof JsonResponse) return $user;
        $extra = $this->owned($user, $id);
        if (!$extra instanceof UserEmail) return $this->error('invalid_request', $locale, 404);
        $address = $extra->getEmail();
        $verified = $extra->isVerified();
        $this->em->remove($extra);
        $this->em->flush();
        if ($verified) $this->mail(fn () => $this->sender->sendSecurityNotice($user, 'email_removed', $address));

        return $this->ok($user, ApiMessages::get('email_removed', $locale));
    }

    #[Route('/api/me/identities/{provider}', requirements: ['provider' => 'google|apple'], methods: ['DELETE'])]
    public function unlink(string $provider, Request $request): JsonResponse
    {
        [$user, $locale] = $this->context($request);
        if ($user instanceof JsonResponse) return $user;
        $identity = $this->em->getRepository(OAuthIdentity::class)->findOneBy(['user' => $user, 'provider' => $provider]);
        if (!$identity instanceof OAuthIdentity) return $this->error('invalid_request', $locale, 404);
        if ($this->emails->signInMethods($user) <= 1) return $this->error('oauth_last_method', $locale, 409);
        $this->em->remove($identity);
        $this->em->flush();
        $name = $provider === 'apple' ? 'Apple' : 'Google';
        $this->mail(fn () => $this->sender->sendSecurityNotice($user, 'unlinked', $name));

        return $this->ok($user, sprintf(ApiMessages::get('oauth_unlinked', $locale), $name));
    }

    /** @return array{0: User|JsonResponse, 1: string} */
    private function context(Request $request, bool $mutation = true): array
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return [$this->error('unsupported_language', 'bs', 400), 'bs'];
        if ($mutation && ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale))) return [$error, $locale];
        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isDeleted()) return [$this->error('authentication_required', $locale, 401), $locale];

        return [$user, $locale];
    }

    /** Accounts with a password confirm it; Google/Apple-only accounts never set one and are already signed in through it. */
    private function passwordError(User $user, array $data, string $locale): ?JsonResponse
    {
        if (!$user->isPasswordKnown()) return null;
        $password = $data['password'] ?? null;

        return is_string($password) && strlen($password) <= 4096 && $this->hasher->isPasswordValid($user, $password)
            ? null : $this->error('current_password_invalid', $locale, 400);
    }

    private function extra(User $user, string $email): ?UserEmail
    {
        $extra = $this->em->getRepository(UserEmail::class)->findOneBy(['email' => $email]);

        return $extra instanceof UserEmail && $extra->getUser() === $user ? $extra : null;
    }

    private function owned(User $user, int $id): ?UserEmail
    {
        $extra = $this->em->getRepository(UserEmail::class)->find($id);

        return $extra instanceof UserEmail && $extra->getUser() === $user ? $extra : null;
    }

    private function mail(\Closure $send): void
    {
        try {
            $send();
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Unable to send an account address email.', ['exception' => $exception]);
        }
    }

    private function ok(User $user, ?string $message = null, int $status = 200, array $extra = []): JsonResponse
    {
        return new JsonResponse(['data' => $this->emails->resource($user)] + ($message === null ? [] : ['message' => $message]) + $extra, $status, ['Cache-Control' => 'private, no-store']);
    }

    private function error(string $key, string $locale, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ApiMessages::get($key, $locale), 'code' => $key], $status);
    }
}
