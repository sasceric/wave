<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261006120000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Add campaign invitations and per-user browser push subscriptions.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE campaign_invitation (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              message CLOB NOT NULL,
              status VARCHAR(20) NOT NULL,
              created_at DATETIME NOT NULL,
              responded_at DATETIME DEFAULT NULL,
              campaign_id INTEGER NOT NULL,
              creator_id INTEGER NOT NULL,
              inviter_id INTEGER NOT NULL,
              CONSTRAINT FK_360A410AF639F774 FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_360A410A61220EA6 FOREIGN KEY (creator_id) REFERENCES creator (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_360A410AB79F4F04 FOREIGN KEY (inviter_id) REFERENCES wave_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_campaign_invitation_pair ON campaign_invitation (campaign_id, creator_id)
        SQL);
        $this->addSql('CREATE INDEX IDX_360A410AF639F774 ON campaign_invitation (campaign_id)');
        $this->addSql('CREATE INDEX IDX_360A410A61220EA6 ON campaign_invitation (creator_id)');
        $this->addSql('CREATE INDEX IDX_360A410AB79F4F04 ON campaign_invitation (inviter_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE user_push_subscription (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              endpoint CLOB NOT NULL,
              endpoint_hash VARCHAR(64) NOT NULL,
              public_key VARCHAR(255) NOT NULL,
              auth_token VARCHAR(255) NOT NULL,
              locale VARCHAR(5) NOT NULL,
              created_at DATETIME NOT NULL,
              user_id INTEGER NOT NULL,
              CONSTRAINT FK_AE378BD8A76ED395 FOREIGN KEY (user_id) REFERENCES wave_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_user_push_subscription_endpoint_hash ON user_push_subscription (endpoint_hash)
        SQL);
        $this->addSql('CREATE INDEX IDX_AE378BD8A76ED395 ON user_push_subscription (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE campaign_invitation');
        $this->addSql('DROP TABLE user_push_subscription');
    }
}
