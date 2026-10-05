<?php

namespace App\Service;

use App\Entity\CampaignMessage;
use App\Entity\Notification;
use App\Entity\UserPushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

final class WebPushNotificationSender
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $subject,
        private readonly UnreadInboxCounter $unreadInboxCounter,
    ) {
    }

    public function send(Notification $notification): void
    {
        if ('' === $this->publicKey || '' === $this->privateKey) {
            return;
        }

        $subscriptions = $this->entityManager->getRepository(UserPushSubscription::class)
            ->findBy(['user' => $notification->getRecipient()]);
        if ([] === $subscriptions) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->subject,
                    'publicKey' => $this->publicKey,
                    'privateKey' => $this->privateKey,
                ],
            ]);
        } catch (\ErrorException $exception) {
            $this->logger->error('Web Push VAPID configuration is invalid.', ['exception' => $exception]);

            return;
        }

        $badgeCount = $this->unreadInboxCounter->total($notification->getRecipient());
        $expiredSubscriptions = [];
        foreach ($subscriptions as $subscription) {
            if (!$subscription instanceof UserPushSubscription) {
                continue;
            }

            try {
                $report = $webPush->sendOneNotification(
                    Subscription::create([
                        'endpoint' => $subscription->getEndpoint(),
                        'keys' => [
                            'p256dh' => $subscription->getPublicKey(),
                            'auth' => $subscription->getAuthToken(),
                        ],
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    json_encode([
                        'title' => $this->titleFor($notification),
                        'body' => $this->bodyFor($notification, $subscription->getLocale()),
                        'url' => $this->targetFor($notification),
                        'badgeCount' => $badgeCount,
                    ], JSON_THROW_ON_ERROR),
                    ['TTL' => 86400],
                );
            } catch (\ErrorException|\Random\RandomException $exception) {
                $this->logger->warning('Unable to send a Wave Web Push notification.', [
                    'notification_id' => $notification->getId(),
                    'subscription_id' => $subscription->getId(),
                    'exception' => $exception,
                ]);

                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $expiredSubscriptions[] = $subscription;
            } elseif (!$report->isSuccess()) {
                $this->logger->warning('A Wave Web Push notification was rejected.', [
                    'notification_id' => $notification->getId(),
                    'subscription_id' => $subscription->getId(),
                    'reason' => $report->getReason(),
                ]);
            }
        }

        foreach ($expiredSubscriptions as $subscription) {
            $this->entityManager->remove($subscription);
        }
        if ([] !== $expiredSubscriptions) {
            $this->entityManager->flush();
        }
    }

    private function titleFor(Notification $notification): string
    {
        $actor = $notification->getActor();
        $actorName = $actor?->getCreator()?->getDisplayName()
            ?? $actor?->getCompany()?->getName()
            ?? 'Wave';

        return '' !== trim($actorName) ? $actorName : 'Wave';
    }

    private function bodyFor(Notification $notification, string $locale): string
    {
        $conversation = $notification->getConversation();
        $sender = $notification->getActor();
        if ($conversation !== null && $sender !== null) {
            $message = $this->entityManager->getRepository(CampaignMessage::class)->findOneBy(
                ['conversation' => $conversation, 'sender' => $sender],
                ['createdAt' => 'DESC', 'id' => 'DESC'],
            );
            if ($message instanceof CampaignMessage) {
                return $this->trimPreview($message->getBody());
            }
        }

        return $this->messageForLocale($locale);
    }

    private function trimPreview(string $message): string
    {
        $preview = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        if (mb_strlen($preview) <= 160) {
            return $preview;
        }

        $preview = mb_substr($preview, 0, 159);
        $lastSpace = mb_strrpos($preview, ' ');
        if ($lastSpace !== false && $lastSpace > 100) {
            $preview = mb_substr($preview, 0, $lastSpace);
        }

        return rtrim($preview).'…';
    }

    private function messageForLocale(string $locale): string
    {
        return match ($locale) {
            'bs' => 'Imaš novo obavještenje. Otvori Wave za detalje.',
            'hr' => 'Imaš novu obavijest. Otvori Wave za detalje.',
            'sr' => 'Имате ново обавештење. Отворите Wave за детаље.',
            'cnr' => 'Imaš novo obavještenje. Otvori Wave za detalje.',
            'sl' => 'Imate novo obvestilo. Odprite Wave za podrobnosti.',
            default => 'You have a new notification. Open Wave for details.',
        };
    }

    private function targetFor(Notification $notification): string
    {
        if (null !== $notification->getConversation()?->getId()) {
            return '/messages?conversation='.$notification->getConversation()->getId();
        }

        return match ($notification->getType()) {
            'campaign_invitation', 'invitation_accepted', 'invitation_declined' => '/account/invitations',
            'creator_inquiry_received', 'creator_inquiry_accepted' => '/account/inquiries',
            'offer_received' => '/account/offers',
            default => '/account',
        };
    }
}
