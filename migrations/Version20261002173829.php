<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002173829 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE company (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, name VARCHAR(120) NOT NULL, industry VARCHAR(100) NOT NULL, logo_url VARCHAR(500) DEFAULT NULL, verified BOOLEAN NOT NULL)');
        $this->addSql('CREATE TABLE creator (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, display_name VARCHAR(120) NOT NULL, category VARCHAR(80) NOT NULL, location VARCHAR(120) NOT NULL, bio CLOB NOT NULL, avatar_url VARCHAR(500) DEFAULT NULL, social_profiles CLOB NOT NULL, tags CLOB NOT NULL)');
        $this->addSql('CREATE TABLE campaign (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(160) NOT NULL, summary VARCHAR(220) NOT NULL, description CLOB NOT NULL, category VARCHAR(80) NOT NULL, channels CLOB NOT NULL, deliverables CLOB NOT NULL, budget_min INTEGER NOT NULL, budget_max INTEGER NOT NULL, location VARCHAR(120) NOT NULL, creator_count INTEGER NOT NULL, closes_at DATETIME NOT NULL, published_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, featured BOOLEAN NOT NULL, company_id INTEGER NOT NULL, CONSTRAINT FK_1F1512DD979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1F1512DD979B1AD6 ON campaign (company_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE campaign');
        $this->addSql('DROP TABLE company');
        $this->addSql('DROP TABLE creator');
    }
}
