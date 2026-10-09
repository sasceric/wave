<?php

namespace App\Api;

use App\Entity\User;

final class UserResource
{
    public static function fromEntity(User $user, string $locale, array $categoryLabels = []): array
    {
        $profile = $user->getCreator() ?? $user->getCompany();

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'city' => $user->getCity(),
            'countryCode' => $user->getCountryCode(),
            'accountType' => $user->hasRole('ROLE_CREATOR') ? 'creator' : 'company',
            'emailVerified' => $user->isEmailVerified(),
            'approved' => $user->isApproved(),
            'profileComplete' => $user->hasCompleteProfile(),
            'isModerator' => $user->hasRole('ROLE_MODERATOR'),
            'isAdmin' => $user->hasRole('ROLE_ADMIN'),
            'hide_my_account' => !$user->isApproved() || $user->isHideMyAccount() ? 1 : 0,
            'notificationsEnabled' => $user->isNotificationsEnabled(),
            'profile' => $profile instanceof \App\Entity\Creator
                ? CreatorResource::fromEntity($profile, $locale, categoryLabels: $categoryLabels) + ['birthday' => $profile->getBirthday()?->format('Y-m-d')]
                : ($profile instanceof \App\Entity\Company ? CompanyResource::fromEntity($profile, $locale, categoryLabels: $categoryLabels) : null),
        ];
    }
}
