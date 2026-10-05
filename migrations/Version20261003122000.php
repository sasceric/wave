<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

final class Version20261003122000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Add admin access, featured creators, and homepage curation settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user ADD admin BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE creator ADD featured BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE creator ADD created_at DATETIME DEFAULT \'1970-01-01 00:00:00\' NOT NULL');
        $this->addSql('UPDATE creator SET created_at = CURRENT_TIMESTAMP');
        $this->addSql('CREATE TABLE homepage_settings (id INTEGER NOT NULL, creator_mode VARCHAR(20) DEFAULT \'latest\' NOT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO homepage_settings (id, creator_mode) VALUES (1, \'latest\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE homepage_settings');
        $this->addSql('ALTER TABLE creator DROP COLUMN created_at');
        $this->addSql('ALTER TABLE creator DROP COLUMN featured');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN admin');
    }
}
