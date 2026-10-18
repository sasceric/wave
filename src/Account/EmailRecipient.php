<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;

final class EmailRecipient
{
    public static function name(?User $user): string
    {
        if ($user === null || $user->isDeleted()) return '';
        // An agency may also have a creator studio: address its company identity.
        $company = self::cleanName($user->getCompany()?->getName() ?? '');

        return $company !== '' ? $company : self::cleanName($user->getCreator()?->getDisplayName() ?? '');
    }

    public static function cleanName(string $name): string
    {
        return trim(preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
    }

    public static function greeting(string $base, string $name): string
    {
        $base = preg_replace('/[\s!,.:;]+$/u', '', trim($base)) ?? '';
        $name = self::cleanName($name);

        return $base === '' ? $name : $base.($name === '' ? '' : ', '.$name).'!';
    }
}
