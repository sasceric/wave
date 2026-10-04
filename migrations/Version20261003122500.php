<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003122500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align homepage settings identity with Doctrine.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__homepage_settings AS SELECT id, creator_mode FROM homepage_settings');
        $this->addSql('DROP TABLE homepage_settings');
        $this->addSql('CREATE TABLE homepage_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, creator_mode VARCHAR(20) DEFAULT \'latest\' NOT NULL)');
        $this->addSql('INSERT INTO homepage_settings (id, creator_mode) SELECT id, creator_mode FROM __temp__homepage_settings');
        $this->addSql('DROP TABLE __temp__homepage_settings');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__homepage_settings AS SELECT id, creator_mode FROM homepage_settings');
        $this->addSql('DROP TABLE homepage_settings');
        $this->addSql('CREATE TABLE homepage_settings (id INTEGER NOT NULL, creator_mode VARCHAR(20) DEFAULT \'latest\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO homepage_settings (id, creator_mode) SELECT id, creator_mode FROM __temp__homepage_settings');
        $this->addSql('DROP TABLE __temp__homepage_settings');
    }
}
