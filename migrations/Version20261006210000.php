<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261006210000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Index the conversation inbox and latest inquiry message lookup.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_conversation_creator_activity ON campaign_conversation (creator_id, updated_at, id)');
        $this->addSql('CREATE INDEX idx_conversation_campaign_activity ON campaign_conversation (campaign_id, updated_at, id)');
        $this->addSql('CREATE INDEX idx_inquiry_message_latest ON inquiry_message (inquiry_id, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_conversation_creator_activity');
        $this->addSql('DROP INDEX idx_conversation_campaign_activity');
        $this->addSql('DROP INDEX idx_inquiry_message_latest');
    }
}
