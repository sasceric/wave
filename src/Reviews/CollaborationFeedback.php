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
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function deadline(Application $application): ?DateTimeImmutable
    {
        return $application->getCampaign()->getFinishedAt()?->modify('+14 days');
    }

    public function eligible(Application $application): bool
    {
        return $application->getCampaign()->getStatus() === 'finished'
            && $application->getOffer()?->getStatus() === 'accepted'
            && $application->getCreator()->getOwner() !== null
            && $application->getCampaign()->getCompany()->getOwner() !== null
            && $application->getCreator()->getOwner()->getId() !== $application->getCampaign()->getCompany()->getOwner()->getId()
            && !$application->getCreator()->getOwner()->isDeleted()
            && !$application->getCampaign()->getCompany()->getOwner()->isDeleted();
    }

    /** SQL shared by list and average queries so hidden feedback never leaks through totals. */
    private function publicCondition(): string
    {
        return "c.status = 'finished' AND o.status = 'accepted' AND c.finished_at IS NOT NULL
            AND (c.finished_at <= :cutoff OR EXISTS (SELECT 1 FROM campaign_review pair WHERE pair.application_id = r.application_id AND pair.author_id = r.recipient_id AND pair.recipient_id = r.author_id))";
    }

    /** @param list<int> $ids
     *  @return array<int, array{average: ?float, reviewCount: int, completedCampaigns: int}>
     */
    public function summaries(array $ids): array
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
            JOIN campaign_offer o ON o.application_id = a.id WHERE r.recipient_id IN (:ids) AND '.$this->publicCondition().' GROUP BY r.recipient_id',
            ['ids' => $ids, 'cutoff' => (new DateTimeImmutable('-14 days'))->format('Y-m-d H:i:s')], ['ids' => ArrayParameterType::INTEGER]);
        foreach ($rows as $row) {
            $result[(int) $row['recipient_id']]['average'] = round((float) $row['average'], 1);
            $result[(int) $row['recipient_id']]['reviewCount'] = (int) $row['count'];
        }
        foreach (['creator' => 'JOIN creator p ON p.id = a.creator_id', 'company' => 'JOIN company p ON p.id = c.company_id'] as $join) {
            $rows = $db->fetchAllAssociative("SELECT p.owner_id, COUNT(DISTINCT c.id) AS count FROM campaign_offer o
                JOIN campaign_application a ON a.id = o.application_id JOIN campaign c ON c.id = a.campaign_id $join
                WHERE p.owner_id IN (:ids) AND c.status = 'finished' AND o.status = 'accepted' GROUP BY p.owner_id",
                ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
            foreach ($rows as $row) {
                $result[(int) $row['owner_id']]['completedCampaigns'] = (int) $row['count'];
            }
        }

        return $result;
    }

    public function summary(?User $user): array
    {
        return $user?->getId() === null ? ['average' => null, 'reviewCount' => 0, 'completedCampaigns' => 0]
            : $this->summaries([$user->getId()])[$user->getId()];
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
        return [
            'id' => $review->getId(),
            'rating' => $review->getRating(),
            'comment' => $review->getComment(),
            'createdAt' => $review->getCreatedAt()->format(DATE_ATOM),
            'campaignTitle' => $review->getApplication()->getCampaign()->getTitle(),
            'authorName' => $author->isDeleted() ? ApiMessages::get('deleted_account', $locale)
                : ($author->getCreator()?->getDisplayName() ?? $author->getCompany()?->getName()),
        ];
    }

    public function publicReviews(User $user, string $locale, int $before = 0): array
    {
        $db = $this->entityManager->getConnection();
        $ids = $db->fetchFirstColumn('SELECT r.id FROM campaign_review r
            JOIN campaign_application a ON a.id = r.application_id JOIN campaign c ON c.id = a.campaign_id
            JOIN campaign_offer o ON o.application_id = a.id WHERE r.recipient_id = :user AND '.$this->publicCondition()
            .($before > 0 ? ' AND r.id < :before' : '').' ORDER BY r.id DESC LIMIT 11',
            ['user' => $user->getId(), 'cutoff' => (new DateTimeImmutable('-14 days'))->format('Y-m-d H:i:s')] + ($before > 0 ? ['before' => $before] : []));
        $hasMore = count($ids) > 10;
        $ids = array_slice($ids, 0, 10);
        $reviews = $ids === [] ? [] : $this->entityManager->createQueryBuilder()->select('r', 'author', 'creator', 'company', 'a', 'c')
            ->from(CampaignReview::class, 'r')->join('r.author', 'author')
            ->leftJoin('author.creator', 'creator')->leftJoin('author.company', 'company')
            ->join('r.application', 'a')->join('a.campaign', 'c')
            ->where('r.id IN (:ids)')->setParameter('ids', $ids)->orderBy('r.id', 'DESC')->getQuery()->getResult();

        return ['data' => array_map(fn (CampaignReview $review): array => $this->resource($review, $locale), $reviews),
            'summary' => $this->summary($user), 'meta' => ['hasMore' => $hasMore, 'nextCursor' => $hasMore ? (int) end($ids) : null]];
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
            WHERE p.owner_id IN (:ids) AND c.status = 'finished' AND o.status = 'accepted' GROUP BY p.owner_id, c.currency ORDER BY c.currency", ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['owner_id']][] = ['currency' => $row['currency'], 'amount' => (int) $row['amount']];
        }
        return $totals;
    }

    public function earnings(User $user): array
    {
        $creator = $user->getCreator() !== null;
        $id = $user->getId();
        return ['kind' => $creator ? 'earnings' : 'spending',
            'totals' => $id === null ? [] : ($this->financialTotals([$id], $creator)[$id] ?? []),
            'rating' => $this->summary($user)];
    }
}
