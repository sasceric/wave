<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261006200000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Durable Messenger jobs, scheduled tasks and worker monitoring.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE background_job (id VARCHAR(32) NOT NULL PRIMARY KEY, type VARCHAR(100) NOT NULL, queue VARCHAR(32) NOT NULL, dedupe_key VARCHAR(255) DEFAULT NULL UNIQUE, payload TEXT NOT NULL, status VARCHAR(20) NOT NULL, attempts INT NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, error_code VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_background_job_status_created ON background_job (status, created_at)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_messenger_receive ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('CREATE TABLE wave_scheduled_task (name VARCHAR(100) NOT NULL PRIMARY KEY, interval_seconds INT NOT NULL, status VARCHAR(20) NOT NULL, next_run_at DATETIME NOT NULL, active_job_id VARCHAR(32) DEFAULT NULL, last_started_at DATETIME DEFAULT NULL, last_finished_at DATETIME DEFAULT NULL, last_outcome VARCHAR(20) DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_wave_task_due ON wave_scheduled_task (status, next_run_at)');
        $this->addSql('CREATE TABLE worker_heartbeat (id VARCHAR(100) NOT NULL PRIMARY KEY, mode VARCHAR(10) NOT NULL, queues TEXT NOT NULL, last_seen_at DATETIME NOT NULL, memory_bytes BIGINT NOT NULL, handled INT NOT NULL)');
        $this->addSql('CREATE INDEX idx_action_token_expiry ON user_action_token (expires_at)');
        if ($this->isPostgreSQL()) {
            $this->addSql("CREATE INDEX idx_messenger_type_queue ON messenger_messages (queue_name, ((headers::jsonb)->>'type'))");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_action_token_expiry');
        $this->addSql('DROP TABLE worker_heartbeat');
        $this->addSql('DROP TABLE wave_scheduled_task');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE background_job');
    }
}
