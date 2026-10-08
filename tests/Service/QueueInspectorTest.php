<?php

namespace App\Tests\Service;

use App\Service\QueueInspector;
use App\Background\JobCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class QueueInspectorTest extends TestCase
{
    public function testCatalogIncludesZeroCountTypesAndTransports(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([]);
        $rows = (new QueueInspector($db))->queues();
        self::assertCount(count(JobCatalog::TYPES) + 5, $rows);
        self::assertSame(array_fill(0, count(JobCatalog::TYPES) + 5, 0), array_column($rows, 'count'));
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
        $types = ['SendEmailMessage', 'SendWebPushMessage', 'UnreadMessageReminderTask', 'SitemapGenerateTask', 'ExpiredTokenCleanupTask', 'QueueMaintenanceTask'];
        $counts = [100, 90, 78, 10, 5, 1];
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn(array_reverse(array_map(
            static fn (string $type, int $count): array => ['queue_name' => JobCatalog::TYPES[$type], 'type' => 'App\\Message\\'.$type, 'size' => (string) $count, 'delayed' => '0', 'in_flight' => '0'],
            $types, $counts,
        )));
        $rows = (new QueueInspector($db))->queues();
        self::assertSame([100, 100, 94, 90, 90, 78, 10, 5, 1], array_slice(array_column($rows, 'count'), 0, 9));
        self::assertSame(['SendEmailMessage', 'messenger.transport.mail'], array_slice(array_column($rows, 'id'), 0, 2));
        self::assertSame($counts, array_values(array_column(array_filter($rows, static fn (array $row): bool => $row['kind'] === 'message' && $row['count'] > 0), 'count')));
        self::assertSame(array_fill(0, count(JobCatalog::TYPES) - 4, 0), array_slice(array_column($rows, 'count'), 9));
    }
}
