<?php

namespace App\Marketplace;

use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignInvitation;
use App\Entity\Notification;
use App\Entity\Offer;
use App\Entity\User;
use App\Service\NotificationDelivery;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CampaignCancellation
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly CampaignOfferTimeline $timeline, private readonly NotificationDelivery $delivery) {}

    public function offer(Offer $offer, User $actor, string $reason): void
    {
        $events = $this->em->wrapInTransaction(function () use ($offer, $actor, $reason): array {
            $campaign = $offer->getApplication()->getCampaign();
            $this->em->lock($campaign, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($campaign);
            $this->em->refresh($offer);
            if ($campaign->getStatus() === 'finished' || $offer->getWorkCompletedAt() !== null) throw new HttpException(409, 'campaign_financial_history_locked');
            if ($offer->getStatus() === 'cancelled') return [];
            if ($offer->getStatus() !== 'accepted') throw new HttpException(409, 'collaboration_not_active');
            $this->validateReason($reason);
            return [$this->record($offer, $actor, trim($reason))];
        });
        $this->deliver($events);
    }

    public function campaign(Campaign $campaign, User $actor, string $reason): void
    {
        $events = $this->em->wrapInTransaction(function () use ($campaign, $actor, $reason): array {
            $this->em->lock($campaign, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($campaign);
            if ($campaign->getStatus() === 'finished') throw new HttpException(409, 'campaign_financial_history_locked');
            if ($campaign->getStatus() === 'cancelled') return [];
            foreach ($this->em->getRepository(Application::class)->findBy(['campaign' => $campaign]) as $application) {
                if ($application->getOffer()?->getWorkCompletedAt() !== null) throw new HttpException(409, 'campaign_financial_history_locked');
            }
            $this->validateReason($reason);
            $reason = trim($reason);
            $events = [];
            $notified = [];
            foreach ($this->em->getRepository(Application::class)->findBy(['campaign' => $campaign]) as $application) {
                $offer = $application->getOffer();
                if ($offer && in_array($offer->getStatus(), ['pending', 'accepted'], true)) {
                    $events[] = $this->record($offer, $actor, $reason);
                    $notified[$application->getCreator()->getId()] = true;
                }
                $recipient = $application->getCreator()->getOwner();
                if ($recipient && !$recipient->isDeleted() && !isset($notified[$application->getCreator()->getId()]) && !in_array($application->getStatus(), ['rejected', 'offer_declined', 'cancelled'], true)) {
                    $notification = new Notification($recipient, 'campaign_cancelled', $actor, $campaign);
                    $this->em->persist($notification);
                    $events[] = ['notification' => $notification, 'message' => null];
                    $notified[$application->getCreator()->getId()] = true;
                }
                $application->setStatus('cancelled');
            }
            foreach ($this->em->getRepository(CampaignInvitation::class)->findBy(['campaign' => $campaign]) as $invitation) {
                if ($invitation->getStatus() === 'declined' || $invitation->getStatus() === 'cancelled') continue;
                $invitation->cancel();
                $recipient = $invitation->getCreator()->getOwner();
                if ($recipient && !$recipient->isDeleted() && !isset($notified[$invitation->getCreator()->getId()])) {
                    $notification = new Notification($recipient, 'campaign_cancelled', $actor, $campaign);
                    $this->em->persist($notification);
                    $events[] = ['notification' => $notification, 'message' => null];
                }
            }
            $campaign->setCancellationReason($reason);
            $campaign->setStatus('cancelled');
            return $events;
        });
        $this->deliver($events);
    }

    private function record(Offer $offer, User $actor, string $reason): array
    {
        $offer->cancel($reason);
        $application = $offer->getApplication();
        $creator = $application->getCreator()->getOwner();
        $company = $application->getCampaign()->getCompany()->getOwner();
        $recipient = $actor->getId() === $creator?->getId() ? $company : $creator;
        $message = $this->timeline->record($offer, $actor, 'cancelled', $recipient?->getPreferredLocale() ?? 'bs');
        $notification = $recipient && !$recipient->isDeleted() ? new Notification($recipient, 'chat_message', $actor, $application->getCampaign(), $message->getConversation()) : null;
        if ($notification) $this->em->persist($notification);
        return ['notification' => $notification, 'message' => $message];
    }

    private function validateReason(string $reason): void
    {
        $length = mb_strlen(trim($reason));
        if ($length < 10 || $length > 1000) throw new HttpException(400, 'invalid_cancellation_reason');
    }

    private function deliver(array $events): void
    {
        foreach ($events as $event) {
            if ($event['notification']) $this->delivery->deliver($event['notification'], $event['message']);
        }
    }
}
