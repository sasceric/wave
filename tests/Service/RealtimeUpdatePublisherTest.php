<?php

namespace App\Tests\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\RealtimeUpdatePublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class RealtimeUpdatePublisherTest extends TestCase
{
    public function testEmptySuccessResponseIsLoggedAsAPublicationFailure(): void
    {
        $recipient = new User('recipient@example.test', 'ROLE_CREATOR');
        $this->setId($recipient, 1);
        $notification = new Notification($recipient, 'offer_received');
        $this->setId($notification, 2);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')
            ->with(self::callback(static fn (Update $update): bool => $update->isPrivate()
                && ['https://wave.local/users/1'] === $update->getTopics()))
            ->willReturn('');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with('Unable to publish a Wave realtime update.', self::callback(
                static fn (array $context): bool => 2 === $context['notification_id']
                    && str_contains($context['exception']->getMessage(), 'empty event ID'),
            ));

        (new RealtimeUpdatePublisher($hub, $logger))->publish($notification);
    }

    public function testAcceptedPublicationDoesNotLogAFailure(): void
    {
        $recipient = new User('recipient@example.test', 'ROLE_CREATOR');
        $this->setId($recipient, 1);
        $notification = new Notification($recipient, 'offer_received');
        $this->setId($notification, 2);
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->willReturn('urn:uuid:test-event');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        (new RealtimeUpdatePublisher($hub, $logger))->publish($notification);
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
