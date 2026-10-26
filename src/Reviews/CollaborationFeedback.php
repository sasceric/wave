<?php

namespace App\Reviews;

use App\Entity\Application;
use App\Entity\CampaignReview;
use App\Entity\User;
use App\Localization\ApiMessages;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final class CollaborationFeedback
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ?\App\Safety\BlockPolicy $blocks = null)
    {
    }

    public function deadline(Application $application): ?DateTimeImmutable
    {
        return ($application->getOffer()?->getWorkCompletedAt() ?? $application->getCampaign()->getFinishedAt())?->modify('+14 days');
    }

    public function eligible(Application $application): bool
    {
        return ($application->getOffer()?->getWorkCompletedAt() !== null || $application->getCampaign()->getStatus() === 'finished')
            && $application->getOffer()?->getStatus() === 'accepted'
            && $application->getCreator()->getOwner() !== null
            && $application->getCampaign()->getCompany()->getOwner() !== null
            && $application->getCreator()->getOwner()->getId() !== $application->getCampaign()->getCompany()->getOwner()->getId()
            && !$application->getCreator()->getOwner()->isDeleted()
            && !$application->getCampaign()->getCompany()->getOwner()->isDeleted()
            && !($this->blocks?->pair($application->getCreator()->getOwner()->getId(), $application->getCampaign()->getCompany()->getOwner()->getId()) ?? false);
    }

    /** SQL shared by list and average queries so hidden feedback never leaks through totals. */
    public function publicCondition(?string $role = null): string
    {
        $scope = match ($role) {
            'provider' => ' AND EXISTS (SELECT 1 FROM creator provider WHERE provider.id = a.creator_id AND provider.owner_id = r.recipient_id)',
            'client' => ' AND EXISTS (SELECT 1 FROM company client WHERE client.id = c.company_id AND client.owner_id = r.recipient_id)',
            default => '',
        };
        return "(o.work_completed_at IS NOT NULL OR c.status = 'finished') AND o.status = 'accepted' AND COALESCE(o.work_completed_at, c.finished_at) IS NOT NULL
            AND (COALESCE(o.work_completed_at, c.finished_at) <= :cutoff OR EXISTS (SELECT 1 FROM campaign_review pair WHERE pair.application_id = r.application_id AND pair.author_id = r.recipient_id AND pair.recipient_id = r.author_id))".$scope;
    }

    /** @param list<int> $ids
     *  @return array<int, array{average: ?float, reviewCount: int, completedCampaigns: int}>
     */
    public function summaries(array $ids, ?string $role = null): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $db = $this->entityManager->getConnection();
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = ['average' => null, 'reviewCount' => 0, 'completedCampaigns' => 0];
        }
        $rows = $db->fetchAllAssociative('SELECT r.recipient_id, AVG(r.rating) AS average, COUNT(*) AS count FROM campaign_review r
            JOIN campaign_application a ON a.id = r.application_id JOIN campaign c ON c.id = a.campaign_id
            JOIN campaign_offer o ON o.id = a.offer_id WHERE r.recipient_id IN (:ids) AND '.$this->publicCondition($role).' GROUP BY r.recipient_id',
            ['ids' => $ids, 'cutoff' => (new DateTimeImmutable('-14 days'))->format('Y-m-d H:i:s')], ['ids' => ArrayParameterType::INTEGER]);
        foreach ($rows as $row) {
            $result[(int) $row['recipient_id']]['average'] = round((float) $row['average'], 1);
            $result[(int) $row['recipient_id']]['reviewCount'] = (int) $row['count'];
        }
        foreach (['creator' => 'JOIN creator p ON p.id = a.creator_id', 'company' => 'JOIN company p ON p.id = c.company_id'] as $side => $join) {
            if ($role !== null && $side !== ($role === 'provider' ? 'creator' : 'company')) continue;
            $completed = $side === 'company' ? "c.status = 'finished'" : "(o.work_completed_at IS NOT NULL OR c.status = 'finished')";
            $rows = $db->fetchAllAssociative("SELECT p.owner_id, COUNT(DISTINCT c.id) AS count FROM campaign_offer o
                JOIN campaign_application a ON a.id = o.application_id JOIN campaign c ON c.id = a.campaign_id $join
                WHERE p.owner_id IN (:ids) AND $completed AND o.status = 'accepted' GROUP BY p.owner_id",
                ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
            foreach ($rows as $row) {
                $result[(int) $row['owner_id']]['completedCampaigns'] += (int) $row['count'];
            }
        }

        return $result;
    }

    public function summary(?User $user, ?string $role = null): array
    {
        return $user?->getId() === null ? ['average' => null, 'reviewCount' => 0, 'completedCampaigns' => 0]
            : $this->summaries([$user->getId()], $role)[$user->getId()];
    }

    /** Company directory reviews follow the public role: agency provider or brand client. */
    public function companySummaries(array $companies): array
    {
        $providers = $clients = [];
        foreach ($companies as $company) {
            $id = $company->getOwner()?->getId();
            if ($id === null) continue;
            if ($company->getCompanyType() === 'agency') $providers[] = $id;
            else $clients[] = $id;
        }
        return $this->summaries($providers, 'provider') + $this->summaries($clients, 'client');
    }

    public function state(Application $application, User $user, string $locale): array
    {
        $reviews = $this->entityManager->getRepository(CampaignReview::class)->findBy(['application' => $application]);
        $own = null;
        $received = null;
        $public = count($reviews) === 2 || ($this->deadline($application) !== null && $this->deadline($application) <= new DateTimeImmutable());
        foreach ($reviews as $review) {
            if ($review->getAuthor()->getId() === $user->getId()) {
                $own = $this->resource($review, $locale);
            } elseif ($public) {
                $received = $this->resource($review, $locale);
            }
        }

        return [
            'canReview' => $this->eligible($application) && $own === null && $this->deadline($application) > new DateTimeImmutable(),
            'deadline' => $this->deadline($application)?->format(DATE_ATOM),
            'own' => $own,
            'received' => $received,
        ];
    }

    public function resource(CampaignReview $review, string $locale): array
    {
        $author = $review->getAuthor();
        $blocked = $this->blocks?->blocked($author->getId()) ?? false;
        return [
            'id' => $review->getId(),
            'isPresentationDemo' => $author->isPresentationDemo() || $review->getRecipient()->isPresentationDemo(),
            'rating' => $review->getRating(),
            'comment' => $blocked ? '' : $review->getComment(),
            'createdAt' => $review->getCreatedAt()->format(DATE_ATOM),
            'campaignTitle' => $blocked ? ApiMessages::get('blocked_account', $locale) : $review->getApplication()->getCampaign()->getTitle(),
            'authorType' => $author->hasRole('ROLE_COMPANY') ? 'Organization' : 'Person',
            'authorName' => $blocked ? ApiMessages::get('blocked_account', $locale) : ($author->isDeleted() ? ApiMessages::get('deleted_account', $locale)
                : ($author->getCompany()?->getName() ?? $author->getCreator()?->getDisplayName())),
        ];
    }

    public function publicReviews(User $user, string $locale, int $before = 0, int $page = 1, ?array $summary = null, bool $forSchema = false, ?string $role = null): array
    {
        $db = $this->entityManager->getConnection();
        $summary ??= $this->summary($user, $role);
        $page = min(max(1, $page), max(1, (int) ceil($summary['reviewCount'] / 10)));
        $ids = $db->fetchFirstColumn('SELECT r.id FROM campaign_review r
            JOIN campaign_application a ON a.id = r.application_id JOIN campaign c ON c.id = a.campaign_id
            JOIN campaign_offer o ON o.id = a.offer_id WHERE r.recipient_id = :user AND '.$this->publicCondition($role)
            .($before > 0 ? ' AND r.id < :before' : '').' ORDER BY r.id DESC LIMIT 11 OFFSET '.($before > 0 ? 0 : ($page - 1) * 10),
            ['user' => $user->getId(), 'cutoff' => (new DateTimeImmutable('-14 days'))->format('Y-m-d H:i:s')] + ($before > 0 ? ['before' => $before] : []));
        $hasMore = count($ids) > 10;
        $ids = array_slice($ids, 0, 10);
        $reviews = $ids === [] ? [] : $this->entityManager->createQueryBuilder()->select('r', 'author', 'creator', 'company', 'a', 'c')
            ->from(CampaignReview::class, 'r')->join('r.author', 'author')
            ->leftJoin('author.creator', 'creator')->leftJoin('author.company', 'company')
            ->join('r.application', 'a')->join('a.campaign', 'c')
            ->where('r.id IN (:ids)')->setParameter('ids', $ids)->orderBy('r.id', \SortDirection::Descending)->getQuery()->getResult();

        $hasDemoReviews = $user->isPresentationDemo();
        if ($forSchema && !$hasDemoReviews && $summary['reviewCount'] > 0) {
            $hasDemoReviews = (bool) $db->fetchOne('SELECT 1 FROM campaign_review r JOIN wave_user author ON author.id = r.author_id
                JOIN campaign_application a ON a.id = r.application_id JOIN campaign c ON c.id = a.campaign_id
                JOIN campaign_offer o ON o.id = a.offer_id WHERE r.recipient_id = :user AND author.presentation_demo_key IS NOT NULL AND '.$this->publicCondition($role).' LIMIT 1',
                ['user' => $user->getId(), 'cutoff' => (new DateTimeImmutable('-14 days'))->format('Y-m-d H:i:s')]);
        }
        return ($forSchema ? ['hasDemoReviews' => $hasDemoReviews] : []) + ['data' => array_map(fn (CampaignReview $review): array => $this->resource($review, $locale), $reviews),
            'summary' => $summary, 'meta' => ['page' => $page, 'limit' => 10, 'total' => $summary['reviewCount'], 'hasMore' => $hasMore, 'nextCursor' => $hasMore ? (int) end($ids) : null]];
    }

    /** @param list<int> $ids
     *  @return array<int, list<array{currency: string, amount: int}>>
     */
    public function financialTotals(array $ids, bool $creator): array
    {
        if ($ids === []) {
            return [];
        }
        $join = $creator ? 'JOIN creator p ON p.id = a.creator_id' : 'JOIN company p ON p.id = c.company_id';
        $rows = $this->entityManager->getConnection()->fetchAllAssociative("SELECT p.owner_id, c.currency, SUM(o.amount) AS amount FROM campaign_offer o
            JOIN campaign_application a ON a.id = o.application_id JOIN campaign c ON c.id = a.campaign_id $join
            WHERE p.owner_id IN (:ids) AND (o.work_completed_at IS NOT NULL OR c.status = 'finished') AND o.status = 'accepted' GROUP BY p.owner_id, c.currency ORDER BY c.currency", ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['owner_id']][] = ['currency' => $row['currency'], 'amount' => (int) $row['amount']];
        }
        return $totals;
    }

    public function earnings(User $user): array
    {
        $creator = $user->hasRole('ROLE_CREATOR');
        $id = $user->getId();
        return ['kind' => $creator ? 'earnings' : 'spending',
            'totals' => $id === null ? [] : ($this->financialTotals([$id], $creator)[$id] ?? []),
            'rating' => $this->summary($user), 'serviceTotals' => $user->isAgency() && $id !== null ? ($this->financialTotals([$id], true)[$id] ?? []) : []];
    }
}
