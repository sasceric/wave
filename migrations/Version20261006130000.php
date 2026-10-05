<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track one unread-message email reminder per conversation participant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_conversation ADD creator_unread_reminder_sent_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE campaign_conversation ADD company_unread_reminder_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_conversation DROP creator_unread_reminder_sent_at');
        $this->addSql('ALTER TABLE campaign_conversation DROP company_unread_reminder_sent_at');
    }
}
