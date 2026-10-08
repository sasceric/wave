<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Run unread-message reminder checks hourly instead of every five minutes.';
    }

    public function up(Schema $schema): void
    {
        $this->changeInterval(300, 3600);
    }

    public function down(Schema $schema): void
    {
        $this->changeInterval(3600, 300);
    }

    private function changeInterval(int $previous, int $interval): void
    {
        $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+'.$interval.' seconds')->format('Y-m-d H:i:s');
        $this->addSql('UPDATE wave_scheduled_task SET interval_seconds = ?, next_run_at = ? WHERE name = ? AND interval_seconds = ?', [
            $interval, $next, 'UnreadMessageReminderTask', $previous,
        ]);
    }
}
