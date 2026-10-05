<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

final class Version20261004133000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Store creator bookmarks for campaigns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE campaign_bookmark (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, campaign_id INTEGER NOT NULL, CONSTRAINT FK_campaign_bookmark_user FOREIGN KEY (user_id) REFERENCES wave_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_campaign_bookmark_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_campaign_bookmark_user_campaign ON campaign_bookmark (user_id, campaign_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE campaign_bookmark');
    }
}
