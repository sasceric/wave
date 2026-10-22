<?php

namespace App\Marketplace;

use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\Offer;
use App\Entity\User;
use App\Localization\ApiMessages;
use Doctrine\ORM\EntityManagerInterface;

final class CampaignOfferTimeline
{
    public function __construct(private readonly EntityManagerInterface $em) {}
    public function record(Offer $offer, User $sender, string $type, string $locale): CampaignMessage
    {
        $application = $offer->getApplication();
        $campaign = $application->getCampaign();
        $conversation = $this->em->getRepository(CampaignConversation::class)->findOneBy(['campaign' => $campaign, 'creator' => $application->getCreator()]);
        if (!$conversation instanceof CampaignConversation) {
            $conversation = new CampaignConversation($campaign, $application->getCreator(), $sender);
            $this->em->persist($conversation);
        }
        $compensation = $campaign->isVoluntary() ? ApiMessages::get('campaign_voluntary', $locale) : $offer->getAmount().' '.($campaign->getCurrency() === 'BAM' ? 'KM' : $campaign->getCurrency());
        $body = ApiMessages::get('hiring_'.$type, $locale).' '.$campaign->getTitle().' · '.$compensation;
        $body .= $offer->getDueDate() ? ' · '.$offer->getDueDate()->format('d.m.Y') : '';
        if ($type === 'work_changes_requested') $body .= "\n".$offer->getWorkChangesReason();
        if ($type === 'cancelled' && $offer->getCancellationReason()) $body .= "\n".$offer->getCancellationReason();
        if ($type === 'offer_sent') $body .= "\n".$offer->getMessage();
        $message = new CampaignMessage($conversation, $sender, $body, [
            'type' => $type, 'offerId' => $offer->getId(), 'amount' => $offer->getAmount(),
            'compensationType' => $campaign->getCompensationType(),
            'dueDate' => $offer->getDueDate()?->format('Y-m-d'),
            'workStatus' => $offer->getWorkStatus(),
            'workChangesReason' => $type === 'work_changes_requested' ? $offer->getWorkChangesReason() : null,
            'cancellationReason' => $type === 'cancelled' ? $offer->getCancellationReason() : null,
            'message' => $type === 'offer_sent' ? $offer->getMessage() : null,
            'currency' => $campaign->getCurrency(), 'campaignTitle' => $campaign->getTitle(), 'campaignSlug' => $campaign->getSlug(),
        ]);
        $this->em->persist($message);
        return $message;
    }
}
