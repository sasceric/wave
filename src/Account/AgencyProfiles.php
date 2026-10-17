<?php

namespace App\Account;

use App\Entity\Creator;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Reuse the collaboration participant and studio model, never a second public profile. */
final class AgencyProfiles
{
    public static function ensure(User $owner, EntityManagerInterface $manager): Creator
    {
        $company = $owner->getCompany();
        if (!$owner->isAgency() || $company === null) {
            throw new \InvalidArgumentException('An agency account is required.');
        }
        return $manager->wrapInTransaction(static function (EntityManagerInterface $manager) use ($owner, $company): Creator {
            $categories = array_map(static fn (string $value): string => mb_substr($value, 0, 80), $company->getIndustries());
            // Serialize first use so simultaneous applications/invitations cannot create duplicate participants.
            if ($manager->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                $manager->getConnection()->executeQuery('SELECT id FROM wave_user WHERE id = ? FOR UPDATE', [$owner->getId()]);
            }
            $provider = $manager->getRepository(Creator::class)->findOneBy(['owner' => $owner]);
            if ($provider !== null) $owner->setCreator($provider);
            if ($provider === null) {
                $provider = new Creator('agency-provider-'.bin2hex(random_bytes(12)), $company->getName(), $categories[0], $owner->getCity() ?? '', $company->getAbout() ?? '', []);
                $provider->markAgencyProfile();
                $owner->setCreator($provider);
                $manager->persist($provider);
            }
            $provider->updateProfile($company->getName(), $categories[0], $owner->getCity() ?? '', $company->getAbout() ?? '', [], $provider->getTags(), $company->getLogoUrl(), '', $provider->getPortfolio(), $provider->getPackages(), $categories, $provider->getFaqs());
            $provider->setAvatarMedia($company->getLogoMedia());
            return $provider;
        });
    }
}
