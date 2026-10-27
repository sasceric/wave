<?php

namespace App\Account;

use App\Entity\{AccountTypeRequest, Company, Creator, User};
use App\Service\PublicUrlSlugs;
use Doctrine\ORM\EntityManagerInterface;

final class AccountTypeChanges
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly PublicUrlSlugs $slugs) {}

    public static function type(User $user): string
    {
        return $user->hasRole('ROLE_CREATOR') ? 'creator' : ($user->getCompany()?->getCompanyType() ?? 'brand');
    }

    public function blockers(User $user, string $target): bool
    {
        if ($target === 'agency') return false; // Agencies retain both client and provider tools.
        $db = $this->em->getConnection();
        $id = $user->getId();
        if ($target === 'creator' && $user->getCompany() !== null && (int) $db->fetchOne("SELECT COUNT(*) FROM campaign WHERE company_id = ? AND status = 'open'", [$user->getCompany()->getId()]) > 0) return true;
        $side = $target === 'brand' ? 'cr.owner_id' : 'co.owner_id';
        foreach ([['campaign_offer', 'campaign_application', 'application_id', 'campaign_id'], ['inquiry_offer', 'creator_inquiry', 'inquiry_id', null]] as [$offer, $subject, $foreign, $campaign]) {
            $join = $campaign !== null ? "JOIN campaign c ON c.id = a.campaign_id JOIN company co ON co.id = c.company_id" : 'JOIN company co ON co.id = a.company_id';
            if ((int) $db->fetchOne("SELECT COUNT(*) FROM $offer o JOIN $subject a ON a.id = o.$foreign JOIN creator cr ON cr.id = a.creator_id $join WHERE $side = ? AND o.status = 'accepted' AND o.work_status <> 'completed'", [$id]) > 0) return true;
        }
        return false;
    }

    /** Call under a lock on both the request and its owner, in the approval transaction. */
    public function apply(AccountTypeRequest $request): void
    {
        $owner = $request->getOwner();
        if ($owner->isDeleted() || self::type($owner) !== $request->getSourceType()) throw new \DomainException('stale');
        $target = $request->getTargetType();
        if ($this->blockers($owner, $target)) throw new \DomainException('activeWork');
        $creator = $owner->getCreator();
        $company = $owner->getCompany();
        if ($target === 'creator') {
            $categories = array_slice(array_values(array_unique([...($creator?->getCategories() ?? []), ...($company?->getIndustries() ?? [])])), 0, 5);
            $categories = array_map(static fn (string $value): string => mb_substr($value, 0, 80), $categories);
            if ($creator === null) {
                $creator = new Creator($this->slugs->reserve('creator', $request->getProfileName()), $request->getProfileName(), $categories[0] ?? '', $owner->getCity() ?? '', '', []);
                $owner->setCreator($creator);
                $this->em->persist($creator);
            }
            $socials = $creator->getSocialProfiles();
            foreach ($company?->getSocialLinks() ?? [] as $link) {
                if (!in_array($link['platform'] ?? '', ['Instagram', 'TikTok', 'YouTube', 'Twitch', 'Spotify'], true)) continue;
                if (!in_array($link['platform'], array_column($socials, 'platform'), true)) $socials[] = [...$link, 'followers' => 0];
            }
            $creator->updateProfile($request->getProfileName(), $categories[0] ?? '', $owner->getCity() ?? '', $creator->getBio() !== '' ? $creator->getBio() : ($company?->getAbout() ?? ''), $socials, $creator->getTags(), $creator->getAvatarUrl() ?: $company?->getLogoUrl(), $creator->getTagline(), $creator->getPortfolio(), $creator->getPackages(), $categories, $creator->getFaqs());
            if ($creator->getAvatarMedia() === null) $creator->setAvatarMedia($company?->getLogoMedia());
            $creator->restoreCreatorProfile();
            $company?->setArchivedProfile(true);
            $owner->switchAccountRole('ROLE_CREATOR');
        } else {
            $industries = array_slice(array_values(array_unique([...($company?->getIndustries() ?? []), ...($creator?->getCategories() ?? [])])), 0, 5);
            if ($company === null) {
                $company = new Company($this->slugs->reserve('company', $request->getProfileName()), $request->getProfileName(), $industries[0] ?? '', $creator?->getAvatarUrl());
                $owner->setCompany($company);
                $this->em->persist($company);
            }
            $company->updateProfile($request->getProfileName(), $industries[0] ?? '', $company->getLogoUrl() ?: $creator?->getAvatarUrl(), $company->getAbout() ?: $creator?->getBio());
            $company->setIndustries($industries);
            if ($company->getLogoMedia() === null) $company->setLogoMedia($creator?->getAvatarMedia());
            $links = $company->getSocialLinks();
            foreach ($creator?->getSocialProfiles() ?? [] as $link) {
                if (!in_array($link['platform'] ?? '', array_column($links, 'platform'), true)) $links[] = ['platform' => $link['platform'], 'url' => $link['url'] ?? ''];
            }
            $company->setSocialLinks($links);
            $company->setCompanyType($target);
            $company->setArchivedProfile(false);
            // Keep this same participant/studio and every foreign-key history reference.
            if ($creator !== null) $creator->markAgencyProfile();
            $owner->switchAccountRole('ROLE_COMPANY');
        }
    }

    public static function resource(AccountTypeRequest $request, bool $admin = false): array
    {
        $owner = $request->getOwner();
        $row = ['id' => $request->getId(), 'sourceType' => $request->getSourceType(), 'targetType' => $request->getTargetType(), 'profileName' => $request->getProfileName(), 'message' => $request->getMessage(), 'status' => $request->getStatus(), 'response' => $request->getResponse(), 'createdAt' => $request->getCreatedAt()->format(DATE_ATOM), 'reviewedAt' => $request->getReviewedAt()?->format(DATE_ATOM)];
        if ($admin) $row['account'] = ['id' => $owner->getId(), 'email' => $owner->getEmail(), 'name' => self::type($owner) === 'creator' ? $owner->getCreator()?->getDisplayName() : $owner->getCompany()?->getName(), 'imageUrl' => self::type($owner) === 'creator' ? $owner->getCreator()?->getAvatarMedia()?->getUrl() ?? $owner->getCreator()?->getAvatarUrl() : $owner->getCompany()?->getLogoMedia()?->getUrl() ?? $owner->getCompany()?->getLogoUrl()];
        return $row;
    }
}
