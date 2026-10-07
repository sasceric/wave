<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\CampaignResource;
use App\Api\MarketplaceCategoryLabels;
use App\Api\Currency;
use App\Api\JsonPayload;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignInvitation;
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
        $invitations = $campaigns === []
            ? []
            : $entityManager->getRepository(CampaignInvitation::class)->findBy(['campaign' => $campaigns]);
        $applications = $campaigns === []
            ? []
            : $entityManager->getRepository(Application::class)->findBy(['campaign' => $campaigns]);
        $invitedCreatorIdsByCampaign = [];
        foreach ($invitations as $invitation) {
            $campaignId = $invitation->getCampaign()->getId();
            $creatorId = $invitation->getCreator()->getId();
            if ($campaignId !== null && $creatorId !== null) {
                $invitedCreatorIdsByCampaign[$campaignId][] = $creatorId;
            }
        }
        $appliedCreatorIdsByCampaign = [];
        foreach ($applications as $application) {
            $campaignId = $application->getCampaign()->getId();
            $creatorId = $application->getCreator()->getId();
            if ($campaignId !== null && $creatorId !== null) {
                $appliedCreatorIdsByCampaign[$campaignId][] = $creatorId;
            }
        }
        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);
        $campaignResources = [];
        foreach ($campaigns as $campaign) {
            $campaignId = $campaign->getId();
            $resource = CampaignResource::fromEntity($campaign, $locale, categoryLabels: $categoryLabels);
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

        $slug = $this->slug($fields['title']);
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
            $fields['location'],
            $fields['creatorCount'],
            $fields['closesAt'],
            new DateTimeImmutable('today'),
            $company,
            status: 'open',
            coverMedia: $coverMedia,
            currency: $fields['currency'],
        );
        $entityManager->persist($campaign);
        $entityManager->flush();

        return new JsonResponse(['data' => CampaignResource::fromEntity($campaign, $locale, categoryLabels: MarketplaceCategoryLabels::forLocale($entityManager, $locale))], 201);
    }

    #[Route('/api/company/campaigns/{id}', name: 'api_company_campaign_update', methods: ['PUT'])]
    public function update(
        int $id,
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
        $fields = $data === null ? null : $this->campaignFields($data, true, $invalidFields);
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
        $campaign->update(
            $fields['title'],
            $fields['summary'],
            $fields['description'],
            $fields['category'],
            $fields['channels'],
            $fields['deliverables'],
            $fields['budgetMin'],
            $fields['budgetMax'],
            $fields['location'],
            $fields['creatorCount'],
            $fields['closesAt'],
            $fields['status'],
            $fields['currency'],
        );
        $campaign->setCoverMedia($coverMedia);
        $entityManager->flush();

        return new JsonResponse(['data' => CampaignResource::fromEntity($campaign, $locale, categoryLabels: MarketplaceCategoryLabels::forLocale($entityManager, $locale))]);
    }

    private function campaignFields(array $data, bool $allowStatus, array &$invalidFields): ?array
    {
        $invalidFields = [];
        $title = $this->text($data, 'title', 5, 160);
        $summary = $this->text($data, 'summary', 10, 220);
        $description = $this->text($data, 'description', 20, 8000);
        $category = $this->text($data, 'category', 2, 80);
        $location = $this->text($data, 'location', 2, 120);
        $channels = $this->stringList($data['channels'] ?? null, 6, 40);
        $deliverables = $this->stringList($data['deliverables'] ?? null, 10, 180);
        $budgetMin = $data['budgetMin'] ?? null;
        $budgetMax = $data['budgetMax'] ?? null;
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
            $invalidFields[] = 'category';
        }
        if ($location === null) {
            $invalidFields[] = 'location';
        }
        if ($channels === null || $channels === []) {
            $invalidFields[] = 'channels';
        }
        if ($deliverables === null || $deliverables === []) {
            $invalidFields[] = 'deliverables';
        }
        if (!is_int($budgetMin) || $budgetMin < 1 || $budgetMin > 10_000_000) {
            $invalidFields[] = 'budgetMin';
        }
        if (!is_int($budgetMax) || $budgetMax < 1 || $budgetMax > 10_000_000
            || (is_int($budgetMin) && $budgetMin >= 1 && $budgetMax < $budgetMin)
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
        if (!in_array($status, $allowStatus ? ['open', 'closed'] : ['open'], true)) {
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
        if ($closesAt instanceof DateTimeImmutable
            && ($closesAt <= new DateTimeImmutable('today') || $closesAt > new DateTimeImmutable('+1 year'))
        ) {
            $invalidFields[] = 'closesAt';
        }
        if ($invalidFields !== []) {
            return null;
        }

        return compact('title', 'summary', 'description', 'category', 'location', 'channels', 'deliverables', 'budgetMin', 'budgetMax', 'currency', 'creatorCount', 'closesAt', 'status');
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

    private function slug(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $ascii)) ?? '', '-');

        return substr($slug !== '' ? $slug : 'campaign', 0, 90).'-'.bin2hex(random_bytes(3));
    }
}
