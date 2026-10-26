<?php

namespace App\Controller\Api;

use App\Account\EmailPreferences;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Api\UserResource;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Optional email choices: signed links from emails, and the signed-in settings page. */
final class EmailPreferenceController
{
    private const FIELDS = ['profileTips' => EmailPreferences::PROFILE_TIPS, 'newsletter' => EmailPreferences::NEWSLETTER, 'campaignAlerts' => EmailPreferences::CAMPAIGN_ALERTS];

    /** RFC 8058 one-click unsubscribe, posted by mail clients from the List-Unsubscribe header. */
    #[Route('/api/email/unsubscribe', name: 'api_email_one_click_unsubscribe', methods: ['POST'])]
    public function oneClick(Request $request, EmailPreferences $preferences): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request) ?? 'bs';
        $subscriber = $request->query->has('n');
        $userId = $subscriber
            ? $preferences->verifySubscriber($request->query->get('n'), $request->query->get('s'))
            : $preferences->verify($request->query->get('u'), $request->query->get('s'));
        $category = $request->query->get('c');
        if ($userId === null || !in_array($category, EmailPreferences::CATEGORIES, true) || ($subscriber && $category !== EmailPreferences::NEWSLETTER)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400, ['Cache-Control' => 'no-store']);
        }
        if ($subscriber) $preferences->setSubscriber($userId, false);
        else $preferences->set($userId, $category, false);

        return new JsonResponse(['data' => ['unsubscribed' => $category]], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/email/preferences', name: 'api_email_preferences_show', methods: ['GET'])]
    public function show(Request $request, EmailPreferences $preferences): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $subscriber = $request->query->has('n');
        $userId = $subscriber
            ? $preferences->verifySubscriber($request->query->get('n'), $request->query->get('s'))
            : $preferences->verify($request->query->get('u'), $request->query->get('s'));
        $states = $userId === null ? null : ($subscriber ? $preferences->subscriberStates($userId) : $preferences->states($userId));
        if ($states === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['data' => $states], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/email/preferences', name: 'api_email_preferences_update', methods: ['PUT'])]
    public function update(Request $request, EmailPreferences $preferences, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) {
            return $error;
        }
        $subscriber = $request->query->has('n');
        $userId = $subscriber
            ? $preferences->verifySubscriber($request->query->get('n'), $request->query->get('s'))
            : $preferences->verify($request->query->get('u'), $request->query->get('s'));
        if ($userId === null || ($subscriber ? $preferences->subscriberStates($userId) : $preferences->states($userId)) === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404, ['Cache-Control' => 'no-store']);
        }
        $changes = $this->changes(JsonPayload::fromRequest($request));
        if ($changes === null || ($subscriber && array_diff(array_keys($changes), [EmailPreferences::NEWSLETTER]) !== [])) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        foreach ($changes as $category => $enabled) {
            if ($subscriber) $preferences->setSubscriber($userId, $enabled);
            else $preferences->set($userId, $category, $enabled);
        }

        return new JsonResponse(['data' => ($subscriber ? $preferences->subscriberStates($userId) : $preferences->states($userId))], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/me/email-preferences', name: 'api_my_email_preferences', methods: ['PUT'])]
    public function mine(Request $request, Security $security, CsrfTokenManagerInterface $csrf, EmailPreferences $preferences, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) {
            return $error;
        }
        $user = ApiAccess::requireParticipant($security, $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $changes = $this->changes(JsonPayload::fromRequest($request));
        // Campaign alerts have their own settings endpoint for signed-in accounts.
        if ($changes === null || isset($changes[EmailPreferences::CAMPAIGN_ALERTS])) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        if (array_key_exists(EmailPreferences::PROFILE_TIPS, $changes)) {
            $user->setProfileTipsEnabled($changes[EmailPreferences::PROFILE_TIPS]);
        }
        if (array_key_exists(EmailPreferences::NEWSLETTER, $changes)) {
            $user->setNewsletterEnabled($changes[EmailPreferences::NEWSLETTER]);
            $records = $user->getLegalRecords();
            $records['newsletter'] = \App\Legal\LegalPolicies::emailRecord($records['newsletter'] ?? [], $changes[EmailPreferences::NEWSLETTER], 'account_settings', $locale);
            $user->setLegalRecords($records);
        }
        $entityManager->getConnection()->transactional(function () use ($entityManager, $changes, $preferences, $user): void {
            $entityManager->flush();
            if (($changes[EmailPreferences::NEWSLETTER] ?? null) === false) {
                $preferences->set((int) $user->getId(), EmailPreferences::NEWSLETTER, false);
                $entityManager->refresh($user);
            }
        });

        return new JsonResponse(['data' => UserResource::fromEntity($user, $locale)], headers: ['Cache-Control' => 'private, no-store']);
    }

    /**
     * @return array<string, bool>|null
     */
    private function changes(mixed $payload): ?array
    {
        if (!is_array($payload) || $payload === []) {
            return null;
        }
        $changes = [];
        foreach ($payload as $field => $value) {
            if (!isset(self::FIELDS[$field]) || !is_bool($value)) {
                return null;
            }
            $changes[self::FIELDS[$field]] = $value;
        }

        return $changes;
    }
}
