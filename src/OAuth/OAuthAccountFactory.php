<?php

namespace App\OAuth;

use App\Api\ProfileSlug;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OAuthAccountFactory
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    /**
     * @param array{email: string, givenName: ?string, familyName: ?string} $identity
     */
    public function create(array $identity, string $accountType, string $locale): User
    {
        if (!in_array($accountType, ['creator', 'company'], true)) {
            throw new \InvalidArgumentException('The OAuth account type is invalid.');
        }

        $name = trim(implode(' ', array_filter([
            $identity['givenName'],
            $identity['familyName'],
        ], static fn (?string $part): bool => is_string($part) && trim($part) !== '')));
        if ($name === '') {
            $name = strstr($identity['email'], '@', true) ?: $identity['email'];
        }
        $name = mb_substr($name, 0, 120);
        if (mb_strlen($name) < 2) {
            $name = $accountType === 'creator' ? 'Wave creator' : 'Wave company';
        }

        $role = $accountType === 'creator' ? 'ROLE_CREATOR' : 'ROLE_COMPANY';
        $user = new User($identity['email'], $role);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(64))));
        $user->setPreferredLocale($locale);
        $user->setEmailVerified(true);
        $user->setApproved(false);
        $user->setHideMyAccount(true);

        if ($accountType === 'creator') {
            $user->setCreator(new Creator(ProfileSlug::fromName($name), $name, '', '', '', []));
        } else {
            $user->setCompany(new Company(ProfileSlug::fromName($name), $name, ''));
        }

        return $user;
    }
}
