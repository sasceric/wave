<?php

namespace App\Api;

use App\Entity\Campaign;
use App\Entity\Offer;
use Doctrine\ORM\EntityManagerInterface;

final class CampaignHiredCounts
{
    /** @param list<Campaign> $campaigns
     *  @return array<int, int>
     */
    public static function forCampaigns(EntityManagerInterface $entityManager, array $campaigns): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (Campaign $campaign): ?int => $campaign->getId(), $campaigns))));
        if ($ids === []) {
            return [];
        }
        $rows = $entityManager->createQueryBuilder()
            ->select('IDENTITY(application.campaign) AS campaignId', 'COUNT(offer.id) AS hiredCount')
            ->from(Offer::class, 'offer')
            ->join('offer.application', 'application')
            ->where('application.campaign IN (:ids)')
            ->andWhere('offer.status = :accepted')
            ->setParameter('ids', $ids)
            ->setParameter('accepted', 'accepted')
            ->groupBy('application.campaign')
            ->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['campaignId']] = (int) $row['hiredCount'];
        }

        return $counts;
    }

    public static function forCampaign(EntityManagerInterface $entityManager, Campaign $campaign): int
    {
        return self::forCampaigns($entityManager, [$campaign])[$campaign->getId()] ?? 0;
    }
}
