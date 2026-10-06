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
}
