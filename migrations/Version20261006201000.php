<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261006201000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Normalized directory search and availability projections.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE directory_index (id VARCHAR(60) NOT NULL PRIMARY KEY, kind VARCHAR(20) NOT NULL, entity_id INT NOT NULL, search_text TEXT NOT NULL, platform_keys TEXT NOT NULL, tag_text TEXT NOT NULL, available_campaign_count INT DEFAULT NULL, indexed_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_directory_kind_entity ON directory_index (kind, entity_id)');
        $this->addSql('CREATE INDEX idx_directory_indexed ON directory_index (indexed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE directory_index');
    }
}
