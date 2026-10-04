<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002175548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("ALTER TABLE campaign ADD COLUMN translations CLOB NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE company ADD COLUMN translations CLOB NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE creator ADD COLUMN translations CLOB NOT NULL DEFAULT '{}'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__campaign AS SELECT id, slug, title, summary, description, category, channels, deliverables, budget_min, budget_max, location, creator_count, closes_at, published_at, status, featured, company_id FROM campaign');
        $this->addSql('DROP TABLE campaign');
        $this->addSql('CREATE TABLE campaign (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(160) NOT NULL, summary VARCHAR(220) NOT NULL, description CLOB NOT NULL, category VARCHAR(80) NOT NULL, channels CLOB NOT NULL, deliverables CLOB NOT NULL, budget_min INTEGER NOT NULL, budget_max INTEGER NOT NULL, location VARCHAR(120) NOT NULL, creator_count INTEGER NOT NULL, closes_at DATETIME NOT NULL, published_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, featured BOOLEAN NOT NULL, company_id INTEGER NOT NULL, CONSTRAINT FK_1F1512DD979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO campaign (id, slug, title, summary, description, category, channels, deliverables, budget_min, budget_max, location, creator_count, closes_at, published_at, status, featured, company_id) SELECT id, slug, title, summary, description, category, channels, deliverables, budget_min, budget_max, location, creator_count, closes_at, published_at, status, featured, company_id FROM __temp__campaign');
        $this->addSql('DROP TABLE __temp__campaign');
        $this->addSql('CREATE UNIQUE INDEX uniq_campaign_slug ON campaign (slug)');
        $this->addSql('CREATE INDEX IDX_1F1512DD979B1AD6 ON campaign (company_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__company AS SELECT id, slug, name, industry, logo_url, verified FROM company');
        $this->addSql('DROP TABLE company');
        $this->addSql('CREATE TABLE company (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, name VARCHAR(120) NOT NULL, industry VARCHAR(100) NOT NULL, logo_url VARCHAR(500) DEFAULT NULL, verified BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO company (id, slug, name, industry, logo_url, verified) SELECT id, slug, name, industry, logo_url, verified FROM __temp__company');
        $this->addSql('DROP TABLE __temp__company');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_slug ON company (slug)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__creator AS SELECT id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags FROM creator');
        $this->addSql('DROP TABLE creator');
        $this->addSql('CREATE TABLE creator (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, display_name VARCHAR(120) NOT NULL, category VARCHAR(80) NOT NULL, location VARCHAR(120) NOT NULL, bio CLOB NOT NULL, avatar_url VARCHAR(500) DEFAULT NULL, social_profiles CLOB NOT NULL, tags CLOB NOT NULL)');
        $this->addSql('INSERT INTO creator (id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags) SELECT id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags FROM __temp__creator');
        $this->addSql('DROP TABLE __temp__creator');
        $this->addSql('CREATE UNIQUE INDEX uniq_creator_slug ON creator (slug)');
    }
}
