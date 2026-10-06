<?php

namespace App\Tests\Service;

use App\Service\ScheduledTaskMonitor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ScheduledTaskMonitorTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    public function testLifecycleAndInterruptedRunsDoNotOverwriteNewerRuns(): void
    {
        $monitor = static::getContainer()->get(ScheduledTaskMonitor::class);
        self::assertSame('neverRun', $monitor->tasks()[0]['status']);
        self::assertNull($monitor->tasks()[0]['nextExpectedAt']);
        $old = $monitor->start(ScheduledTaskMonitor::REMINDERS);
        self::assertSame('running', $monitor->tasks()[0]['status']);
        $new = $monitor->start(ScheduledTaskMonitor::REMINDERS);
        $monitor->finish(ScheduledTaskMonitor::REMINDERS, $old, 1);
        self::assertSame('running', $monitor->tasks()[0]['status']);
        $monitor->finish(ScheduledTaskMonitor::REMINDERS, $new, 0);
        self::assertSame('success', $monitor->tasks()[0]['status']);
        self::assertNotNull($monitor->tasks()[0]['nextExpectedAt']);
        $run = $monitor->start(ScheduledTaskMonitor::REMINDERS);
        $monitor->finish(ScheduledTaskMonitor::REMINDERS, $run, 1);
        self::assertSame('failed', $monitor->tasks()[0]['status']);
    }

    public function testOverdueAndStalledExecutionsAreVisible(): void
    {
        $monitor = static::getContainer()->get(ScheduledTaskMonitor::class);
        $run = $monitor->start(ScheduledTaskMonitor::REMINDERS);
        $later = new \DateTimeImmutable('+2 hours');
        self::assertSame('stalled', $monitor->tasks($later)[0]['status']);
        $monitor->finish(ScheduledTaskMonitor::REMINDERS, $run, 0);
        self::assertSame('overdue', $monitor->tasks($later)[0]['status']);
    }

    public function testRealConsoleExecutionIsRecordedByTheSubscriber(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertSame(0, $tester->run(['command' => ScheduledTaskMonitor::REMINDERS], ['interactive' => false]));
        $row = static::getContainer()->get(ScheduledTaskMonitor::class)->tasks()[0];
        self::assertSame('success', $row['status']);
        self::assertSame(0, $row['exitCode']);
        self::assertNotNull($row['lastFinishedAt']);
    }

    public function testConsoleExceptionsAreRecordedAsFailed(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $command = new \Symfony\Component\Console\Command\Command(ScheduledTaskMonitor::REMINDERS);
        $command->setCode(static function (): never {
            throw new \RuntimeException('Test command failure.');
        });
        $application->addCommand($command);
        $tester = new ApplicationTester($application);
        self::assertSame(1, $tester->run(['command' => ScheduledTaskMonitor::REMINDERS], ['interactive' => false]));
        $row = static::getContainer()->get(ScheduledTaskMonitor::class)->tasks()[0];
        self::assertSame('failed', $row['status']);
        self::assertSame(1, $row['exitCode']);
    }

    public function testCachePruningUsesTheDocumentedUtcSchedule(): void
    {
        $monitor = static::getContainer()->get(ScheduledTaskMonitor::class);
        $run = $monitor->start(ScheduledTaskMonitor::CACHE_PRUNE);
        $monitor->finish(ScheduledTaskMonitor::CACHE_PRUNE, $run, 0);
        $now = new \DateTimeImmutable('2026-10-06T01:00:00+00:00');
        self::assertSame('2026-10-06T02:17:00+00:00', $monitor->tasks($now)[1]['nextExpectedAt']);
        $later = new \DateTimeImmutable('2026-10-06T23:00:00+00:00');
        self::assertSame('2026-10-07T02:17:00+00:00', $monitor->tasks($later)[1]['nextExpectedAt']);
    }
}
