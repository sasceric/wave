<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Entity\UserEmail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which account an address belongs to. Main addresses live on wave_user, extra ones in user_email;
 * an address may belong to only one account in either table.
 */
final class AccountEmails
{
    public const MAX_EXTRA = 3;

    public function __construct(private readonly EntityManagerInterface $em) {}

    public static function normalize(mixed $email): ?string
    {
        if (!is_string($email)) return null;
        $email = mb_strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 180 ? $email : null;
    }

    /** The account that signs in with this address: its main address, or a confirmed extra one. */
    public function signInUser(string $email): ?User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user instanceof User) return $user;
        $extra = $this->em->getRepository(UserEmail::class)->findOneBy(['email' => $email]);

        return $extra instanceof UserEmail && $extra->isVerified() ? $extra->getUser() : null;
    }

    /** The account holding this address in any form (main, confirmed or pending extra), other than $except. */
    public function owner(string $email, ?User $except = null): ?User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user instanceof User && $user !== $except) return $user;
        $extra = $this->em->getRepository(UserEmail::class)->findOneBy(['email' => $email]);
        if ($extra instanceof UserEmail && $extra->getUser() !== $except) return $extra->getUser();

        return null;
    }

    /** @return list<UserEmail> */
    public function extras(User $user): array
    {
        return $this->em->getRepository(UserEmail::class)->findBy(['user' => $user], ['id' => 'ASC']);
    }

    /** @return list<OAuthIdentity> */
    public function identities(User $user): array
    {
        return $this->em->getRepository(OAuthIdentity::class)->findBy(['user' => $user], ['id' => 'ASC']);
    }

    /** Ways this account can sign in: a known password counts once, each linked Google/Apple account once. */
    public function signInMethods(User $user): int
    {
        return ($user->isPasswordKnown() ? 1 : 0) + count($this->identities($user));
    }

    /** @return array<string, mixed> */
    public function resource(User $user): array
    {
        return [
            'primary' => $user->getEmail(),
            'emails' => array_map(static fn (UserEmail $email): array => [
                'id' => $email->getId(), 'email' => $email->getEmail(), 'verified' => $email->isVerified(),
            ], $this->extras($user)),
            'identities' => array_map(static fn (OAuthIdentity $identity): array => [
                'provider' => $identity->getProvider(), 'email' => $identity->getEmail(),
                'linkedAt' => $identity->getLinkedAt()?->format(DATE_ATOM),
            ], $this->identities($user)),
            'passwordKnown' => $user->isPasswordKnown(),
            'maxExtra' => self::MAX_EXTRA,
        ];
    }
}
