<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\CampaignResource;
use App\Api\MarketplaceCategoryLabels;
use App\Entity\Campaign;
use App\Entity\CampaignBookmark;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignBookmarkController
{
    #[Route('/api/me/bookmarks', name: 'api_my_campaign_bookmarks', methods: ['GET'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireRole($security, 'ROLE_CREATOR', $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $bookmarks = $entityManager->getRepository(CampaignBookmark::class)->createQueryBuilder('bookmark')
            ->join('bookmark.campaign', 'campaign')
            ->join('campaign.company', 'company')
            ->leftJoin('company.owner', 'companyOwner')
            ->andWhere('bookmark.user = :user')
            ->andWhere('campaign.status = :status')
            ->andWhere('campaign.closesAt >= :today')
            ->andWhere('companyOwner.id IS NULL OR (companyOwner.approved = :approved AND companyOwner.hideMyAccount = :visible)')
            ->setParameter('user', $user)
            ->setParameter('status', 'open')
            ->setParameter('today', new DateTimeImmutable('today'))
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->orderBy('bookmark.createdAt', SortDirection::Descending)
            ->getQuery()
            ->getResult();
        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);

        return new JsonResponse([
            'data' => array_map(
                static fn (CampaignBookmark $bookmark): array => CampaignResource::fromEntity(
                    $bookmark->getCampaign(),
                    $locale,
                    $categoryLabels[$bookmark->getCampaign()->getCategory()] ?? null,
                ),
                $bookmarks,
            ),
        ]);
    }

    #[Route('/api/campaigns/{slug}/bookmark', name: 'api_campaign_bookmark_create', methods: ['POST'])]
    public function create(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireRole($security, 'ROLE_CREATOR', $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $campaign = $this->availableCampaign($slug, $locale, $entityManager);
        if ($campaign instanceof JsonResponse) {
            return $campaign;
        }

        $repository = $entityManager->getRepository(CampaignBookmark::class);
        $bookmark = $repository->findOneBy(['user' => $user, 'campaign' => $campaign]);
        $created = !($bookmark instanceof CampaignBookmark);
        if ($created) {
            $bookmark = new CampaignBookmark($user, $campaign);
            $entityManager->persist($bookmark);
            $entityManager->flush();
        }

        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);

        return new JsonResponse([
            'data' => CampaignResource::fromEntity(
                $campaign,
                $locale,
                $categoryLabels[$campaign->getCategory()] ?? null,
            ),
        ], $created ? 201 : 200);
    }

    #[Route('/api/campaigns/{slug}/bookmark', name: 'api_campaign_bookmark_delete', methods: ['DELETE'])]
    public function delete(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireRole($security, 'ROLE_CREATOR', $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $campaign = $entityManager->getRepository(Campaign::class)->findOneBy(['slug' => $slug]);
        if (!$campaign instanceof Campaign) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }

        $bookmark = $entityManager->getRepository(CampaignBookmark::class)->findOneBy([
            'user' => $user,
            'campaign' => $campaign,
        ]);
        if ($bookmark instanceof CampaignBookmark) {
            $entityManager->remove($bookmark);
            $entityManager->flush();
        }

        return new JsonResponse(['data' => ['slug' => $slug, 'bookmarked' => false]]);
    }

    private function availableCampaign(
        string $slug,
        string $locale,
        EntityManagerInterface $entityManager,
    ): Campaign|JsonResponse {
        $campaign = $entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
            ->join('campaign.company', 'company')
            ->leftJoin('company.owner', 'companyOwner')
            ->andWhere('campaign.slug = :slug')
            ->andWhere('campaign.status = :status')
            ->andWhere('campaign.closesAt >= :today')
            ->andWhere('companyOwner.id IS NULL OR (companyOwner.approved = :approved AND companyOwner.hideMyAccount = :visible)')
            ->setParameter('slug', $slug)
            ->setParameter('status', 'open')
            ->setParameter('today', new DateTimeImmutable('today'))
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->getQuery()
            ->getOneOrNullResult();

        return $campaign instanceof Campaign
            ? $campaign
            : new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
    }
}
