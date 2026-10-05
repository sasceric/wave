<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261006160000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Index chat history cursors and store direct inquiry read receipts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_campaign_message_history ON campaign_message (conversation_id, id)');
        $this->addSql('CREATE INDEX idx_inquiry_message_history ON inquiry_message (inquiry_id, id)');
        $this->addSql('ALTER TABLE inquiry_message ADD read_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_campaign_message_receipt ON campaign_message (conversation_id, sender_id, id) WHERE read_at IS NOT NULL');
        $this->addSql('CREATE INDEX idx_inquiry_message_receipt ON inquiry_message (inquiry_id, sender_id, id) WHERE read_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_campaign_message_receipt');
        $this->addSql('DROP INDEX idx_inquiry_message_receipt');
        $this->addSql('DROP INDEX idx_campaign_message_history');
        $this->addSql('DROP INDEX idx_inquiry_message_history');
        $this->addSql('ALTER TABLE inquiry_message DROP read_at');
    }
}
