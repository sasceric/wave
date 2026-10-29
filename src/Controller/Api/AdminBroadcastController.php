<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Admin\AdminBroadcast;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Admin → Slanje e-mailova: compose one email and send it to a chosen audience. */
#[Route('/api/admin/broadcast')]
final class AdminBroadcastController
{
    public function __construct(
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AdminBroadcast $broadcast,
        private readonly LoggerInterface $logger,
        #[Target('wave_email_template_test')] private readonly RateLimiterFactoryInterface $testLimiter,
    ) {
    }

    #[Route('/audience', methods: ['GET'])]
    public function audience(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        $role = $request->query->getString('role', 'all');
        if (!in_array($role, AdminBroadcast::ROLES, true)) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);

        return new JsonResponse(['data' => $this->broadcast->counts($role)], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;

        return new JsonResponse(['data' => $this->broadcast->userOptions(mb_substr($request->query->getString('q'), 0, 100), max(1, $request->query->getInt('page', 1)))], headers: ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Body: {subject, heading, message, buttonLabel, buttonUrl, audience, role, userIds?, emails?, test?}.
     * "test": true sends only to the signed-in admin.
     */
    #[Route('', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale, requireVerified: true);
        if ($admin instanceof JsonResponse) return $admin;
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;

        $data = JsonPayload::fromRequest($request) ?? [];
        $text = static fn (string $key, int $max): ?string => is_string($data[$key] ?? null) && mb_strlen(trim($data[$key])) <= $max
            ? trim(str_replace("\r\n", "\n", $data[$key])) : null;
        $content = [
            'subject' => $text('subject', AdminBroadcast::SUBJECT_MAX),
            'heading' => $text('heading', AdminBroadcast::SUBJECT_MAX),
            'message' => $text('message', AdminBroadcast::MESSAGE_MAX),
            'buttonLabel' => $text('buttonLabel', 80),
            'buttonUrl' => $text('buttonUrl', 500),
        ];
        $audience = is_string($data['audience'] ?? null) ? $data['audience'] : '';
        $role = is_string($data['role'] ?? null) ? $data['role'] : 'all';
        $test = ($data['test'] ?? false) === true;
        $userIds = $data['userIds'] ?? null;
        $emails = $data['emails'] ?? null;
        $validUrl = is_string($content['buttonUrl'])
            && (str_starts_with($content['buttonUrl'], 'https://') || preg_match('/^\{\{\s*profileUrl\s*\}\}$/', $content['buttonUrl']) === 1);
        if (in_array(null, $content, true) || in_array('', [$content['subject'], $content['heading'], $content['buttonLabel']], true)
            || mb_strlen($content['message']) < 10 || !$validUrl
            || !in_array($audience, AdminBroadcast::AUDIENCES, true) || !in_array($role, AdminBroadcast::ROLES, true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        /** @var array{subject: string, heading: string, message: string, buttonLabel: string, buttonUrl: string} $content */
        if (AdminBroadcast::unknownVariables(...array_values($content)) !== []) {
            return new JsonResponse(['error' => ApiMessages::get('broadcast_unknown_variable', $locale)], 400);
        }
        if ($test) {
            if (!$this->testLimiter->create('broadcast:'.$admin->getId())->consume()->isAccepted()) {
                return new JsonResponse(['error' => ApiMessages::get('rate_limited', $locale)], 429);
            }
            $this->broadcast->sendTest($content, $admin);

            return new JsonResponse(['data' => ['sent' => 1, 'skipped' => 0, 'test' => true]], headers: ['Cache-Control' => 'private, no-store']);
        }

        $ids = null;
        $addresses = null;
        if ($audience === 'users') {
            if (!is_array($userIds) || !array_is_list($userIds) || $userIds === [] || count($userIds) > 5000
                || array_filter($userIds, static fn (mixed $value): bool => !is_int($value) || $value < 1) !== []) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
            }
            $ids = $userIds;
        }
        if ($audience === 'emails') {
            if (!is_array($emails) || !array_is_list($emails) || $emails === [] || count($emails) > AdminBroadcast::EMAILS_MAX) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
            }
            $addresses = [];
            foreach ($emails as $email) {
                if (!is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
                    return new JsonResponse(['error' => ApiMessages::get('broadcast_invalid_email', $locale), 'fields' => ['emails']], 400);
                }
                $addresses[] = trim($email);
            }
        }

        $result = $this->broadcast->send($content, $audience, $role, $ids, $addresses, $locale);
        $this->logger->notice('admin.broadcast_sent', ['actorId' => $admin->getId(), 'audience' => $audience, 'role' => $role, 'subject' => $content['subject']] + $result);

        return new JsonResponse(['data' => $result], headers: ['Cache-Control' => 'private, no-store']);
    }
}
