<?php

namespace App\Marketplace;

use App\Entity\Campaign;
use App\Entity\Offer;
use Doctrine\ORM\EntityManagerInterface;

final class CampaignWork
{
    public static function chatOpen(Campaign $campaign, ?Offer $offer): bool
    {
        if (in_array($campaign->getStatus(), ['finished', 'cancelled'], true) || $offer?->getStatus() === 'cancelled') return false;
        return $campaign->getStatus() !== 'closed' || $offer?->getStatus() === 'accepted';
    }

    public static function hasUnfinishedHires(EntityManagerInterface $em, Campaign $campaign): bool
    {
        return (int) $em->createQueryBuilder()->select('COUNT(o.id)')->from(Offer::class, 'o')
            ->join('o.application', 'a')->where('a.campaign = :campaign AND a.offer = o')
            ->andWhere("o.status = 'accepted' AND o.workCompletedAt IS NULL")
            ->setParameter('campaign', $campaign)->getQuery()->getSingleScalarResult() > 0;
    }
}
