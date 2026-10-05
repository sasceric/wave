<?php

namespace App\Service;

use App\Api\CampaignMessageResource;
use App\Entity\CampaignMessage;
use App\Entity\Notification;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureRuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class RealtimeUpdatePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
    ) {
    }

    public function publish(Notification $notification, ?CampaignMessage $message = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $notificationId = $notification->getId();
        $userId = $notification->getRecipient()->getId();
        if (null === $notificationId || null === $userId) {
            throw new \LogicException('Persist a notification before publishing its realtime update.');
        }

        $conversation = $notification->getConversation();
        $this->publishPayload($notification->getRecipient(), [
            'type' => 'notification',
            'notificationId' => $notificationId,
            'notificationType' => $notification->getType(),
            'conversationId' => $conversation?->getId(),
            'message' => $message === null ? null : CampaignMessageResource::fromEntity($message),
        ], ['notification_id' => $notificationId]);
    }

    public function publishEvent(User $recipient, array $payload): void
    {
        if ($this->enabled) {
            $this->publishPayload($recipient, $payload);
        }
    }

    private function publishPayload(User $recipient, array $payload, array $context = []): void
    {
        try {
            $eventId = $this->hub->publish(new Update(
                $this->topicFor($recipient),
                json_encode($payload, JSON_THROW_ON_ERROR),
                true,
            ));
            if ('' === trim($eventId)) {
                throw new MercureRuntimeException('The Mercure publisher endpoint returned an empty event ID. Check the publisher URL and the Hub Host routing.');
            }
        } catch (MercureRuntimeException $exception) {
            $this->logger->warning('Unable to publish a Wave realtime update.', [
                ...$context,
                'exception' => $exception,
            ]);
        }
    }

    public function topicFor(User $user): string
    {
        $userId = $user->getId();
        if (null === $userId) {
            throw new \LogicException('Persist a user before creating a realtime topic.');
        }

        return 'https://wave.local/users/'.$userId;
    }
}
