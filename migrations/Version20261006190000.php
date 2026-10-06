<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261006190000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Track the latest scheduled command execution for admin tools.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scheduled_task_state (name VARCHAR(100) NOT NULL, run_id VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, exit_code INT DEFAULT NULL, PRIMARY KEY(name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduled_task_state');
    }
}
