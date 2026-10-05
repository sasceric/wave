<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261005110000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Add campaign-scoped conversations, messages, and in-app notifications.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE campaign_conversation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_message_preview VARCHAR(240) NOT NULL, campaign_id INTEGER NOT NULL, creator_id INTEGER NOT NULL, last_message_sender_id INTEGER NOT NULL, CONSTRAINT FK_D73EF7D7F639F774 FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D73EF7D761220EA6 FOREIGN KEY (creator_id) REFERENCES creator (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D73EF7D735072873 FOREIGN KEY (last_message_sender_id) REFERENCES wave_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_campaign_conversation_campaign_creator ON campaign_conversation (campaign_id, creator_id)');
        $this->addSql('CREATE INDEX IDX_D73EF7D7F639F774 ON campaign_conversation (campaign_id)');
        $this->addSql('CREATE INDEX IDX_D73EF7D761220EA6 ON campaign_conversation (creator_id)');
        $this->addSql('CREATE INDEX IDX_D73EF7D735072873 ON campaign_conversation (last_message_sender_id)');
        $this->addSql('CREATE TABLE campaign_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, conversation_id INTEGER NOT NULL, sender_id INTEGER NOT NULL, CONSTRAINT FK_1E07AC539AC0396 FOREIGN KEY (conversation_id) REFERENCES campaign_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1E07AC53F624B39D FOREIGN KEY (sender_id) REFERENCES wave_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1E07AC539AC0396 ON campaign_message (conversation_id)');
        $this->addSql('CREATE INDEX IDX_1E07AC53F624B39D ON campaign_message (sender_id)');
        $this->addSql('CREATE TABLE wave_notification (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, recipient_id INTEGER NOT NULL, actor_id INTEGER DEFAULT NULL, campaign_id INTEGER DEFAULT NULL, conversation_id INTEGER DEFAULT NULL, CONSTRAINT FK_DC43E268E92F8F78 FOREIGN KEY (recipient_id) REFERENCES wave_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DC43E26810DAF24A FOREIGN KEY (actor_id) REFERENCES wave_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DC43E268F639F774 FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DC43E2689AC0396 FOREIGN KEY (conversation_id) REFERENCES campaign_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_DC43E268E92F8F78 ON wave_notification (recipient_id)');
        $this->addSql('CREATE INDEX IDX_DC43E26810DAF24A ON wave_notification (actor_id)');
        $this->addSql('CREATE INDEX IDX_DC43E268F639F774 ON wave_notification (campaign_id)');
        $this->addSql('CREATE INDEX IDX_DC43E2689AC0396 ON wave_notification (conversation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE wave_notification');
        $this->addSql('DROP TABLE campaign_message');
        $this->addSql('DROP TABLE campaign_conversation');
    }
}
