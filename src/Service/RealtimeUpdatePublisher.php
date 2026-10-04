<?php

namespace App\Service;

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

    public function publish(Notification $notification): void
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
        $payload = json_encode([
            'type' => 'notification',
            'notificationId' => $notificationId,
            'conversationId' => $conversation?->getId(),
        ], JSON_THROW_ON_ERROR);

        try {
            $this->hub->publish(new Update(
                $this->topicFor($notification->getRecipient()),
                $payload,
                true,
            ));
        } catch (MercureRuntimeException $exception) {
            $this->logger->warning('Unable to publish a Wave realtime update.', [
                'notification_id' => $notificationId,
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
