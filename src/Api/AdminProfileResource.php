<?php

declare(strict_types=1);

namespace App\Api;

use App\Account\AgencyServices;
use App\Entity\{Company, Creator, User};

/** Saved profile information for authenticated administrators only. */
final class AdminProfileResource
{
    public static function owner(Creator|Company|User $item): ?User
    {
        return $item instanceof User ? $item : $item->getOwner();
    }

    public static function summary(Creator|Company|User $item): array
    {
        $owner = self::owner($item);
        $company = $item instanceof Company ? $item : ($item instanceof User ? $item->getCompany() : null);
        $creator = $item instanceof Creator ? $item : $owner?->getCreator();
        $profile = $company ?? $creator;
        $media = $company !== null ? $company->getLogoMedia() : $creator?->getAvatarMedia();
        $imageUrl = $company !== null ? $company->getLogoUrl() : $creator?->getAvatarUrl();
        $contact = $owner !== null && trim($owner->getPhone() ?? '') !== '' && trim($owner->getCity() ?? '') !== '' && preg_match('/^[A-Z]{2}$/', $owner->getCountryCode() ?? '') === 1;
        $identity = $company !== null
            ? mb_strlen(trim($company->getName())) >= 2 && mb_strlen(trim($company->getIndustry())) >= 2
            : $creator !== null && mb_strlen(trim($creator->getDisplayName())) >= 2 && array_filter($creator->getCategories(), static fn (string $value): bool => trim($value) !== '') !== [];
        $completion = [
            'contact' => (int) $contact,
            'identity' => (int) $identity,
            'image' => (int) ($media !== null || trim($imageUrl ?? '') !== ''),
            'description' => (int) (trim(strip_tags($company !== null ? ($company->getAbout() ?? '') : ($creator?->getBio() ?? ''))) !== ''),
            'networks' => count($company !== null ? $company->getSocialLinks() : ($creator?->getSocialProfiles() ?? [])),
        ];
        if ($company !== null) {
            $completion['cover'] = (int) ($company->getCoverMedia() !== null);
            if ($company->getCompanyType() === 'agency' || $creator?->isAgencyProfile()) $completion['services'] = count(AgencyServices::saved($company));
        }
        if ($company === null || $company->getCompanyType() === 'agency' || $creator?->isAgencyProfile()) {
            $completion += ['works' => count($creator?->getPortfolio() ?? []), 'packages' => count($creator?->getPackages() ?? []), 'faqs' => count($creator?->getFaqs() ?? [])];
        }
        $strength = $owner !== null ? \App\Account\ProfileStrength::forUser($owner)['score'] ?? null : null;
        $manageable = $owner !== null && !$owner->isDeleted() && !$owner->hasRole('ROLE_ADMIN') && !$owner->hasRole('ROLE_MODERATOR');

        return [
            'accountId' => $owner?->getId(),
            'email' => $owner?->getEmail(),
            'accountType' => $company !== null || $owner?->hasRole('ROLE_COMPANY') ? 'company' : 'creator',
            'approved' => $owner?->isApproved() ?? false,
            'emailVerified' => $owner?->isEmailVerified() ?? false,
            'profileComplete' => $owner?->hasCompleteProfile() ?? false,
            'hideMyAccount' => $owner?->isHideMyAccount() ?? false,
            'publicVisible' => $profile !== null && ($owner === null || (!$owner->isDeleted() && $owner->isApproved() && !$owner->isHideMyAccount())),
            'canChangeVisibility' => $manageable,
            'isAdmin' => $owner?->hasRole('ROLE_ADMIN') ?? false,
            // Staff rights can be given to any real, verified creator or company account.
            'canChangeAdmin' => $owner !== null && !$owner->isDeleted() && $owner->isEmailVerified() && !$owner->isPresentationDemo()
                && in_array($owner->getRoles()[0] ?? null, ['ROLE_CREATOR', 'ROLE_COMPANY'], true),
            'canShowProfile' => $manageable && $owner->isApproved() && $owner->isEmailVerified() && $owner->hasCompleteProfile(),
            'profileImage' => MediaImageResource::fromEntity($media, 96),
            'profileImageUrl' => $media?->getUrl() ?? $imageUrl,
            'completion' => $completion,
            // Same 0–100 score the account sees on Pregled (null for staff and profiles without an account).
            'profileStrength' => $strength,
            'profileReminders' => $owner !== null && !$owner->isDeleted() ? self::reminders($owner, $strength) : null,
        ];
    }

    /** Profile completion emails (days 1, 4, 10, 21): what was sent, what is next, and what an admin may send now. */
    private static function reminders(User $owner, ?int $score): array
    {
        $total = count(\App\Background\ProfileReminderBatch::SCHEDULE_DAYS);
        $sent = min($total, $owner->getProfileReminderStage());
        $log = $owner->getProfileReminderLog();
        $stages = [];
        foreach (range(1, $total) as $stage) {
            $sends = array_values(array_filter($log, static fn (array $row): bool => (int) ($row['stage'] ?? 0) === $stage));
            $last = $sends === [] ? null : $sends[count($sends) - 1];
            $stages[] = [
                'stage' => $stage,
                'day' => \App\Background\ProfileReminderBatch::SCHEDULE_DAYS[$stage - 1],
                // Emails sent before the history existed are known only from the stage counter.
                'sent' => $stage <= $sent || $last !== null,
                'sentAt' => $last['at'] ?? null,
                'manual' => (bool) ($last['manual'] ?? false),
                'sends' => count($sends),
            ];
        }
        $eligible = $owner->isApproved() && $owner->isEmailVerified() && $owner->isProfileTipsEnabled() && $score !== null && $score < 100;

        return [
            'stages' => $stages,
            'lastStage' => $sent > 0 ? $sent : null,
            'nextStage' => $sent < $total ? $sent + 1 : null,
            'nextAt' => $sent < $total ? $owner->getProfileReminderNextAt()?->format(DATE_ATOM) : null,
            'tipsEnabled' => $owner->isProfileTipsEnabled(),
            'status' => match (true) {
                !$owner->isApproved() || !$owner->isEmailVerified() => 'pending_approval',
                !$owner->isProfileTipsEnabled() => 'tips_disabled',
                $score !== null && $score >= 100 => 'complete',
                $sent >= $total => 'finished',
                default => 'active',
            },
            'canSend' => $eligible,
        ];
    }

    public static function details(Creator|Company|User $item, string $locale, array $categoryLabels): array
    {
        $owner = self::owner($item);
        if ($owner !== null) {
            $data = UserResource::fromEntity($owner, $locale, $categoryLabels);
        } else {
            $data = ['profile' => $item instanceof Creator
                ? CreatorResource::fromEntity($item, $locale, categoryLabels: $categoryLabels, public: false)
                : CompanyResource::fromEntity($item, $locale, categoryLabels: $categoryLabels, public: false)];
        }
        // Review the author's saved copy, not a public locale/demo substitution.
        $creator = $item instanceof Creator ? $item : $owner?->getCreator();
        if (($data['accountType'] ?? null) === 'creator' || ($owner === null && $item instanceof Creator)) {
            $data['profile']['bio'] = $creator?->getBio();
            $data['profile']['tagline'] = $creator?->getTagline();
        }

        return array_replace($data, self::summary($item));
    }
}
