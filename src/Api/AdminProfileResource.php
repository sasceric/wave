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
            'canShowProfile' => $manageable && $owner->isApproved() && $owner->isEmailVerified() && $owner->hasCompleteProfile(),
            'profileImage' => MediaImageResource::fromEntity($media, 96),
            'profileImageUrl' => $media?->getUrl() ?? $imageUrl,
            'completion' => $completion,
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
