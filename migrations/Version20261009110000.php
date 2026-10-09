<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Link support tickets to accounts and add private threaded replies and ticket notifications.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_support_ticket ADD COLUMN owner_id INT DEFAULT NULL REFERENCES wave_user(id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wave_support_ticket ADD COLUMN assigned_to_id INT DEFAULT NULL REFERENCES wave_user(id) ON DELETE SET NULL');
        $this->addSql("ALTER TABLE wave_support_ticket ADD COLUMN priority VARCHAR(10) DEFAULT 'normal' NOT NULL");
        $this->addSql('ALTER TABLE wave_support_ticket ADD COLUMN updated_at TIMESTAMP DEFAULT NULL');
        $this->addSql('ALTER TABLE wave_support_ticket ADD COLUMN last_reply_preview VARCHAR(240) DEFAULT NULL');
        $this->addSql('UPDATE wave_support_ticket SET owner_id = (SELECT id FROM wave_user WHERE LOWER(wave_user.email) = LOWER(wave_support_ticket.email) ORDER BY id LIMIT 1), updated_at = created_at');
        $this->addSql("UPDATE wave_support_ticket SET status = 'open' WHERE status = 'received'");
        $this->addSql('CREATE INDEX idx_support_owner_activity ON wave_support_ticket (owner_id, updated_at, id)');
        $this->addSql('CREATE INDEX idx_support_activity ON wave_support_ticket (updated_at, id)');
        $this->addSql('CREATE INDEX idx_support_assignment ON wave_support_ticket (assigned_to_id)');
        $table = $schema->createTable('wave_support_ticket_message');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('ticket_id', 'integer');
        $table->addColumn('sender_id', 'integer', ['notnull' => false]);
        $table->addColumn('sender_name', 'string', ['length' => 180]);
        $table->addColumn('staff', 'boolean');
        $table->addColumn('internal', 'boolean');
        $table->addColumn('body', 'text');
        $table->addColumn('attachments', 'json');
        $table->addColumn('submission_key', 'string', ['length' => 64]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['ticket_id', 'id'], 'idx_support_message_history');
        $table->addUniqueIndex(['ticket_id', 'submission_key'], 'uniq_support_reply_key');
        $table->addForeignKeyConstraint('wave_support_ticket', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('wave_user', ['sender_id'], ['id'], ['onDelete' => 'SET NULL']);
        $this->addSql('ALTER TABLE wave_notification ADD COLUMN support_ticket_id INT DEFAULT NULL REFERENCES wave_support_ticket(id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_notification_support ON wave_notification (recipient_id, support_ticket_id, read_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notification_support');
        $this->addSql('ALTER TABLE wave_notification DROP COLUMN support_ticket_id');
        $schema->dropTable('wave_support_ticket_message');
        $this->addSql('DROP INDEX idx_support_owner_activity');
        $this->addSql('DROP INDEX idx_support_activity');
        $this->addSql('DROP INDEX idx_support_assignment');
        foreach (['owner_id', 'assigned_to_id', 'priority', 'updated_at', 'last_reply_preview'] as $column) $this->addSql('ALTER TABLE wave_support_ticket DROP COLUMN '.$column);
    }
}
