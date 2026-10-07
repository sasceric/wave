<?php

namespace App\Tests\Service;

use App\Service\QueueInspector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class QueueInspectorTest extends TestCase
{
    public function testCatalogIncludesZeroCountTypesAndTransports(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([]);
        $rows = (new QueueInspector($db))->queues();
        self::assertCount(21, $rows);
        self::assertSame(array_fill(0, 21, 0), array_column($rows, 'count'));
    }

    public function testMessageAndTransportCountsOverlapAndIncludeDelayed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([['queue_name' => 'mail', 'type' => 'App\\Message\\SendEmailMessage', 'size' => '4', 'delayed' => '2', 'in_flight' => '1']]);
        $rows = array_column((new QueueInspector($db))->queues(), null, 'id');
        self::assertSame(4, $rows['SendEmailMessage']['count']);
        self::assertSame(4, $rows['messenger.transport.mail']['count']);
        self::assertSame(2, $rows['messenger.transport.mail']['delayed']);
        self::assertSame(1, $rows['messenger.transport.mail']['inFlight']);
    }

    public function testQueuesSortByNumericMessageCountDescendingWithStableTies(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([
            ['queue_name' => 'mail', 'type' => 'App\\Message\\SendEmailMessage', 'size' => '2', 'delayed' => '0', 'in_flight' => '0'],
            ['queue_name' => 'push', 'type' => 'App\\Message\\SendWebPushMessage', 'size' => '10', 'delayed' => '1', 'in_flight' => '0'],
        ]);
        $rows = (new QueueInspector($db))->queues();
        self::assertSame([10, 10, 2, 2], array_slice(array_column($rows, 'count'), 0, 4));
        self::assertSame(['SendWebPushMessage', 'messenger.transport.push', 'SendEmailMessage', 'messenger.transport.mail'], array_slice(array_column($rows, 'id'), 0, 4));
        self::assertSame(array_fill(0, 17, 0), array_slice(array_column($rows, 'count'), 4));
    }
}
