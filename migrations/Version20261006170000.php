<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261006170000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Index the first unread message for campaign and inquiry chat dividers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_campaign_message_unread ON campaign_message (conversation_id, sender_id, id) WHERE read_at IS NULL');
        $this->addSql('CREATE INDEX idx_inquiry_message_unread ON inquiry_message (inquiry_id, sender_id, id) WHERE read_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_campaign_message_unread');
        $this->addSql('DROP INDEX idx_inquiry_message_unread');
    }
}
