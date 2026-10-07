<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Credits\CreditException;
use App\Credits\CreditService;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CreditsController
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/credits/settings', methods: ['GET'])]
    public function publicSettings(Request $request): JsonResponse
    {
        if (LocaleContext::fromRequest($request) === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $settings = $this->credits->settings();
        unset($settings['welcomeGrant'], $settings['version'], $settings['activation'], $settings['activatedAt']);

        return new JsonResponse(['data' => $settings], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/me/credits', methods: ['GET'])]
    public function account(Request $request): JsonResponse
    {
        return $this->run($request, false, fn (User $user): array => $this->credits->account(
            $user,
            max(1, $request->query->getInt('page', 1)),
            $request->query->getString('type', 'all'),
        ));
    }

    #[Route('/api/me/credits/redeem', methods: ['POST'])]
    public function redeem(Request $request, #[Target('wave_credit_redeem')] RateLimiterFactoryInterface $limiter): JsonResponse
    {
        return $this->run($request, false, function (User $user) use ($request, $limiter): array {
            if (!$limiter->create((string) $user->getId())->consume()->isAccepted()) {
                throw new CreditException('rate_limited', 429);
            }
            $data = JsonPayload::fromRequest($request);
            $code = is_string($data['code'] ?? null) ? $data['code'] : '';
            $amount = $this->credits->redeem($user, $code);

            return ['added' => $amount, ...$this->credits->account($user)];
        });
    }

    #[Route('/api/admin/credits/settings', methods: ['GET', 'PUT'])]
    public function settings(Request $request): JsonResponse
    {
        return $this->run($request, true, fn (): array => $request->isMethod('GET')
            ? $this->credits->settings()
            : $this->credits->saveSettings(JsonPayload::fromRequest($request) ?? []));
    }

    #[Route('/api/admin/credits/vouchers', methods: ['GET', 'POST'])]
    public function vouchers(Request $request): JsonResponse
    {
        return $this->run($request, true, function (User $user) use ($request): array {
            if ($request->isMethod('GET')) {
                return $this->credits->vouchers(max(1, $request->query->getInt('page', 1)));
            }
            $data = JsonPayload::fromRequest($request);
            if (!is_int($data['amount'] ?? null)) {
                throw new CreditException('credit_invalid_pack');
            }

            return $this->credits->issue($user, $data['amount']);
        });
    }

    #[Route('/api/admin/credits/vouchers/{id}/revoke', methods: ['POST'])]
    public function revoke(string $id, Request $request): JsonResponse
    {
        return $this->run($request, true, function () use ($id): array {
            $this->credits->revoke($id);

            return ['revoked' => true];
        });
    }

    private function run(Request $request, bool $admin, \Closure $action): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if (!$request->isMethod('GET') && ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale))) {
            return $error;
        }
        $role = $admin ? 'ROLE_ADMIN' : ($this->security->isGranted('ROLE_COMPANY') ? 'ROLE_COMPANY' : 'ROLE_CREATOR');
        $user = ApiAccess::requireRole($this->security, $role, $locale, requireVerified: !$request->isMethod('GET'));
        if ($user instanceof JsonResponse) {
            return $user;
        }
        try {
            return new JsonResponse(['data' => $action($user)], headers: ['Cache-Control' => 'no-store']);
        } catch (CreditException $error) {
            return new JsonResponse(['error' => ApiMessages::get($error->key, $locale), 'code' => $error->key], $error->status);
        }
    }
}
