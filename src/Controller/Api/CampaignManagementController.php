<?php

namespace App\Controller\Api;

use App\Api\CampaignHiredCounts;
use App\Api\ApiAccess;
use App\Api\CampaignResource;
use App\Api\MarketplaceCategoryLabels;
use App\Api\Currency;
use App\Api\JsonPayload;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignInvitation;
use App\Marketplace\CampaignCancellation;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Entity\Company;
use App\Entity\Media;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignManagementController
{
    public function __construct(private readonly \App\Service\RichTextSanitizer $richText)
    {
    }

    #[Route('/api/me/campaigns', name: 'api_my_campaigns', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $company = $user->getCompany();
        if (!$company instanceof Company) {
            return new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }
        $campaigns = $entityManager->getRepository(Campaign::class)->findBy(['company' => $company], ['publishedAt' => 'DESC']);
        // The list needs identity pairs, not full applications and invitation messages.
        $invitedCreatorIdsByCampaign = $this->participantIds($entityManager, CampaignInvitation::class, $campaigns);
        $appliedCreatorIdsByCampaign = $this->participantIds($entityManager, Application::class, $campaigns);
        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);
        $hiredCounts = CampaignHiredCounts::forCampaigns($entityManager, $campaigns);
        $campaignResources = [];
        foreach ($campaigns as $campaign) {
            $campaignId = $campaign->getId();
            $resource = CampaignResource::fromEntity($campaign, $locale, categoryLabels: $categoryLabels, hiredCount: $hiredCounts[$campaignId] ?? 0);
            $resource['invitedCreatorIds'] = null !== $campaignId ? ($invitedCreatorIdsByCampaign[$campaignId] ?? []) : [];
            $resource['appliedCreatorIds'] = null !== $campaignId ? ($appliedCreatorIdsByCampaign[$campaignId] ?? []) : [];
            $campaignResources[] = $resource;
        }

        return new JsonResponse(['data' => $campaignResources]);
    }

    #[Route('/api/company/campaigns', name: 'api_company_campaign_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        \App\Credits\CreditService $credits,
        \App\Service\PublicUrlSlugs $publicSlugs,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $company = $user->getCompany();
        if (!$company instanceof Company) {
            return new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }
        $data = JsonPayload::fromRequest($request);
        $invalidFields = [];
        $fields = $data === null ? null : $this->campaignFields($data, false, $invalidFields);
        // Free (voluntary) campaigns are reserved for Wave's own campaigns.
        if ($fields !== null && $fields['compensationType'] === Campaign::COMPENSATION_VOLUNTARY && !$user->hasRole('ROLE_ADMIN')) {
            return new JsonResponse(['error' => ApiMessages::get('voluntary_campaign_admin_only', $locale), 'fields' => ['compensationType']], 403);
        }
        $coverMedia = $data === null ? false : $this->ownedCoverMedia($data['coverMediaId'] ?? null, $user, $entityManager);
        if ($fields === null || $coverMedia === false) {
            if ($coverMedia === false) {
                $invalidFields[] = 'coverMediaId';
            }

            return new JsonResponse([
                'error' => ApiMessages::get('invalid_campaign', $locale),
                'fields' => array_values(array_unique($invalidFields)),
            ], 400);
        }

        $slug = $publicSlugs->reserve('campaign', $fields['title']);
        $campaign = new Campaign(
            $slug,
            $fields['title'],
            $fields['summary'],
            $fields['description'],
            $fields['category'],
            $fields['channels'],
            $fields['deliverables'],
            $fields['budgetMin'],
            $fields['budgetMax'],
            $fields['city'],
            $fields['creatorCount'],
            $fields['closesAt'],
            new DateTimeImmutable('today'),
            $company,
            status: 'open',
            coverMedia: $coverMedia,
            currency: $fields['currency'],
            countryCode: $fields['countryCode'],
            categories: $fields['categories'],
            compensationType: $fields['compensationType'],
        );
        $entityManager->persist($campaign);
        try {
            $credits->spend($user, 'campaign', function () use ($entityManager, $campaign): array {
                $entityManager->flush();

                return ['id' => $campaign->getId(), 'title' => $campaign->getTitle()];
            });
        } catch (\App\Credits\CreditException $error) {
            return new JsonResponse(['error' => ApiMessages::get($error->key, $locale), 'code' => $error->key], $error->status);
        }

        return new JsonResponse(['data' => CampaignResource::fromEntity($campaign, $locale, categoryLabels: MarketplaceCategoryLabels::forLocale($entityManager, $locale), hiredCount: CampaignHiredCounts::forCampaign($entityManager, $campaign))], 201);
    }

    #[Route('/api/company/campaigns/{id}', name: 'api_company_campaign_update', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        \App\Service\UnusedMediaCleanup $unusedMedia,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $company = $user->getCompany();
        $campaign = $entityManager->getRepository(Campaign::class)->find($id);
        if (!$company instanceof Company || !$campaign instanceof Campaign || $campaign->getCompany()->getId() !== $company->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }
        $data = JsonPayload::fromRequest($request);
        $invalidFields = [];
        if ($data !== null && !array_key_exists('compensationType', $data)) $data['compensationType'] = $campaign->getCompensationType();
        $fields = $data === null ? null : $this->campaignFields($data, true, $invalidFields);
        // Existing free campaigns stay editable; switching to free needs an administrator.
        if ($fields !== null && $fields['compensationType'] === Campaign::COMPENSATION_VOLUNTARY
            && !$campaign->isVoluntary() && !$user->hasRole('ROLE_ADMIN')) {
            return new JsonResponse(['error' => ApiMessages::get('voluntary_campaign_admin_only', $locale), 'fields' => ['compensationType']], 403);
        }
        $coverMedia = $data === null
            ? false
            : (array_key_exists('coverMediaId', $data)
                ? $this->ownedCoverMedia($data['coverMediaId'], $user, $entityManager)
                : $campaign->getCoverMedia());
        if ($fields === null || $coverMedia === false) {
            if ($coverMedia === false) {
                $invalidFields[] = 'coverMediaId';
            }

            return new JsonResponse([
                'error' => ApiMessages::get('invalid_campaign', $locale),
                'fields' => array_values(array_unique($invalidFields)),
            ], 400);
        }
        return $entityManager->wrapInTransaction(function () use ($entityManager, $campaign, $fields, $coverMedia, $unusedMedia, $locale): JsonResponse {
            $entityManager->lock($campaign, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($campaign);
            if ($fields['compensationType'] !== $campaign->getCompensationType()
                && (in_array($campaign->getStatus(), ['finished', 'cancelled'], true)
                    || $entityManager->getRepository(Application::class)->count(['campaign' => $campaign]) > 0
                    || $entityManager->getRepository(CampaignInvitation::class)->count(['campaign' => $campaign]) > 0)) {
                return new JsonResponse(['error' => ApiMessages::get('campaign_compensation_locked', $locale)], 409);
            }
            if ($fields['status'] === 'finished' && $campaign->getStatus() !== 'finished' && \App\Marketplace\CampaignWork::hasUnfinishedHires($entityManager, $campaign)) {
                return new JsonResponse(['error' => ApiMessages::get('campaign_work_unfinished', $locale)], 409);
            }
            if ((in_array($campaign->getStatus(), ['finished', 'cancelled'], true) && $fields['status'] !== $campaign->getStatus())
                || ($fields['currency'] !== $campaign->getCurrency() && CampaignHiredCounts::forCampaign($entityManager, $campaign) > 0)) {
                return new JsonResponse(['error' => ApiMessages::get('campaign_financial_history_locked', $locale)], 409);
            }
            if ($fields['status'] === 'cancelled' && $campaign->getStatus() !== 'cancelled') {
                return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
            }
            $campaign->update(
                $fields['title'],
                $fields['summary'],
                $fields['description'],
                $fields['category'],
                $fields['channels'],
                $fields['deliverables'],
                $fields['budgetMin'],
                $fields['budgetMax'],
                $fields['city'],
                $fields['creatorCount'],
                $fields['closesAt'],
                $fields['status'],
                $fields['currency'],
                $fields['countryCode'],
                $fields['categories'],
                $fields['compensationType'],
            );
            $previousCoverId = $campaign->getCoverMedia()?->getId();
            $campaign->setCoverMedia($coverMedia);
            $this->finishApplications($campaign, $entityManager);
            $entityManager->flush();
            $unusedMedia->remove([$previousCoverId]);

            return new JsonResponse(['data' => CampaignResource::fromEntity($campaign, $locale, categoryLabels: MarketplaceCategoryLabels::forLocale($entityManager, $locale), hiredCount: CampaignHiredCounts::forCampaign($entityManager, $campaign))]);
        });
    }

    #[Route('/api/company/campaigns/{id}/status', name: 'api_company_campaign_status', methods: ['PUT'], requirements: ['id' => '\\d+'])]
    public function changeStatus(int $id, Request $request, EntityManagerInterface $entityManager, Security $security, CsrfTokenManagerInterface $tokenManager, CampaignCancellation $cancellation): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($error = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $error;
        }
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $campaign = $entityManager->find(Campaign::class, $id);
        if (!$campaign instanceof Campaign || $campaign->getCompany()->getOwner()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }
        $data = JsonPayload::fromRequest($request);
        $status = $data['status'] ?? null;
        if (!in_array($status, ['closed', 'finished', 'cancelled'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        if (in_array($campaign->getStatus(), ['finished', 'cancelled'], true) && $status !== $campaign->getStatus()) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_financial_history_locked', $locale)], 409);
        }
        if ($status === 'cancelled') {
            $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
            try { $cancellation->campaign($campaign, $user, $reason); }
            catch (HttpException $error) { return new JsonResponse(['error' => ApiMessages::get($error->getMessage(), $locale)], $error->getStatusCode()); }
            return new JsonResponse(['data' => CampaignResource::fromEntity($campaign, $locale, hiredCount: CampaignHiredCounts::forCampaign($entityManager, $campaign))]);
        }
        return $entityManager->wrapInTransaction(function () use ($campaign, $status, $entityManager, $locale): JsonResponse {
            $entityManager->lock($campaign, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($campaign);
            if (in_array($campaign->getStatus(), ['finished', 'cancelled'], true) && $status !== $campaign->getStatus()) {
                return new JsonResponse(['error' => ApiMessages::get('campaign_financial_history_locked', $locale)], 409);
            }
            if ($status === 'finished' && $campaign->getStatus() !== 'finished' && \App\Marketplace\CampaignWork::hasUnfinishedHires($entityManager, $campaign)) {
                return new JsonResponse(['error' => ApiMessages::get('campaign_work_unfinished', $locale)], 409);
            }
            $campaign->setStatus($status);
            $this->finishApplications($campaign, $entityManager);
            $entityManager->flush();
            return new JsonResponse(['data' => CampaignResource::fromEntity($campaign, $locale, hiredCount: CampaignHiredCounts::forCampaign($entityManager, $campaign))]);
        });
    }

    /** @param class-string<Application>|class-string<CampaignInvitation> $entityClass */
    private function participantIds(EntityManagerInterface $entityManager, string $entityClass, array $campaigns): array
    {
        if ($campaigns === []) return [];
        $rows = $entityManager->createQueryBuilder()
            ->select('IDENTITY(p.campaign) AS campaignId', 'IDENTITY(p.creator) AS creatorId')
            ->from($entityClass, 'p')
            ->where('p.campaign IN (:campaigns)')
            ->setParameter('campaigns', $campaigns)
            ->getQuery()->getArrayResult();
        $ids = [];
        foreach ($rows as $row) $ids[(int) $row['campaignId']][] = (int) $row['creatorId'];
        return $ids;
    }

    private function finishApplications(Campaign $campaign, EntityManagerInterface $entityManager): void
    {
        if ($campaign->getStatus() === 'finished') {
            $applications = $entityManager->createQueryBuilder()
                ->select('application', 'offer')
                ->from(Application::class, 'application')
                ->leftJoin('application.offer', 'offer')
                ->where('application.campaign = :campaign')
                ->andWhere('application.status != :hired')
                ->setParameter('campaign', $campaign)
                ->setParameter('hired', 'accepted')
                ->getQuery()
                ->getResult();
            foreach ($applications as $application) {
                $offer = $application->getOffer();
                if ($offer?->getStatus() === 'accepted') {
                    continue;
                }
                if ($offer?->getStatus() === 'pending') {
                    $offer->respond('rejected');
                }
                if ($application->getStatus() !== 'cancelled') $application->setStatus('rejected');
            }
        }
    }

    private function campaignFields(array $data, bool $allowStatus, array &$invalidFields): ?array
    {
        $invalidFields = [];
        $title = $this->text($data, 'title', 5, 160);
        $summary = $this->text($data, 'summary', 10, 220);
        $description = is_string($data['description'] ?? null) && strlen($data['description']) <= 100_000
            ? $this->richText->sanitize($data['description']) : null;
        if ($description !== null && (mb_strlen($this->richText->plainText($description)) < 20 || mb_strlen($this->richText->plainText($description)) > 8000)) {
            $description = null;
        }
        $categories = $this->stringList($data['categories'] ?? null, 10, 80);
        $category = $categories[0] ?? null;
        $city = $this->text($data, 'city', 2, 120);
        $countryCode = is_string($data['countryCode'] ?? null) ? strtoupper(trim($data['countryCode'])) : null;
        if ($countryCode !== null && !\App\Account\CountryCode::isSupported($countryCode)) {
            $countryCode = null;
        }
        $channels = $this->stringList($data['channels'] ?? null, 6, 40);
        $deliverables = $this->stringList($data['deliverables'] ?? null, 10, 180);
        $compensationType = $data['compensationType'] ?? Campaign::COMPENSATION_PAID;
        $voluntary = $compensationType === Campaign::COMPENSATION_VOLUNTARY;
        if (!in_array($compensationType, [Campaign::COMPENSATION_PAID, Campaign::COMPENSATION_VOLUNTARY], true)) {
            $invalidFields[] = 'compensationType';
        }
        $budgetMin = $data['budgetMin'] ?? ($voluntary ? 0 : null);
        $budgetMax = $data['budgetMax'] ?? ($voluntary ? 0 : null);
        $currency = $data['currency'] ?? 'BAM';
        $creatorCount = $data['creatorCount'] ?? null;
        $closesAtInput = $data['closesAt'] ?? null;
        $status = $data['status'] ?? 'open';
        if ($title === null) {
            $invalidFields[] = 'title';
        }
        if ($summary === null) {
            $invalidFields[] = 'summary';
        }
        if ($description === null) {
            $invalidFields[] = 'description';
        }
        if ($category === null) {
            $invalidFields[] = 'categories';
        }
        if ($city === null) {
            $invalidFields[] = 'city';
        }
        if ($countryCode === null) {
            $invalidFields[] = 'countryCode';
        }
        if ($channels === null || $channels === []) {
            $invalidFields[] = 'channels';
        }
        if ($deliverables === null || $deliverables === []) {
            $invalidFields[] = 'deliverables';
        }
        if (!is_int($budgetMin) || ($voluntary ? $budgetMin !== 0 : ($budgetMin < 1 || $budgetMin > 10_000_000))) {
            $invalidFields[] = 'budgetMin';
        }
        if (!is_int($budgetMax) || ($voluntary ? $budgetMax !== 0 : ($budgetMax < 1 || $budgetMax > 10_000_000))
            || (is_int($budgetMin) && $budgetMax < $budgetMin)
        ) {
            $invalidFields[] = 'budgetMax';
        }
        if (!Currency::isSupported($currency)) {
            $invalidFields[] = 'currency';
        }
        if (!is_int($creatorCount) || $creatorCount < 1 || $creatorCount > 100) {
            $invalidFields[] = 'creatorCount';
        }
        if (!is_string($closesAtInput)) {
            $invalidFields[] = 'closesAt';
        }
        if (!in_array($status, $allowStatus ? ['open', 'closed', 'finished', 'cancelled'] : ['open'], true)) {
            $invalidFields[] = 'status';
        }
        $closesAt = null;
        if (is_string($closesAtInput)) {
            try {
                $closesAt = new DateTimeImmutable($closesAtInput);
            } catch (\Exception) {
                $invalidFields[] = 'closesAt';
            }
        }
        $requiresFutureDeadline = !$allowStatus || $status === 'open';
        if ($closesAt instanceof DateTimeImmutable
            && (($requiresFutureDeadline && $closesAt <= new DateTimeImmutable('today'))
                || $closesAt > new DateTimeImmutable('+1 year'))
        ) {
            $invalidFields[] = 'closesAt';
        }
        if ($invalidFields !== []) {
            return null;
        }

        return compact('title', 'summary', 'description', 'category', 'categories', 'city', 'countryCode', 'channels', 'deliverables', 'budgetMin', 'budgetMax', 'compensationType', 'currency', 'creatorCount', 'closesAt', 'status');
    }

    private function text(array $data, string $key, int $minimum, int $maximum): ?string
    {
        if (!is_string($data[$key] ?? null)) {
            return null;
        }
        $value = trim($data[$key]);

        return mb_strlen($value) >= $minimum && mb_strlen($value) <= $maximum ? $value : null;
    }

    private function ownedCoverMedia(
        mixed $value,
        User $user,
        EntityManagerInterface $entityManager,
    ): Media|null|false {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) || $value < 1) {
            return false;
        }

        $media = $entityManager->getRepository(Media::class)->find($value);
        if (!$media instanceof Media || $media->getOwner() !== $user || $media->getFolder()->getSlug() !== 'campaign-cover') {
            return false;
        }

        return $media;
    }

    private function stringList(mixed $value, int $maxCount, int $maxLength): ?array
    {
        if (!is_array($value) || count($value) > $maxCount) {
            return null;
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || mb_strlen(trim($item)) < 1 || mb_strlen(trim($item)) > $maxLength) {
                return null;
            }
            $result[] = trim($item);
        }

        return $result;
    }

}
