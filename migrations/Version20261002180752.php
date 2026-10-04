<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002180752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE campaign_application (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, message CLOB NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, campaign_id INTEGER NOT NULL, creator_id INTEGER NOT NULL, CONSTRAINT FK_7C91E13BF639F774 FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7C91E13B61220EA6 FOREIGN KEY (creator_id) REFERENCES creator (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_application_campaign_creator ON campaign_application (campaign_id, creator_id)');
        $this->addSql('CREATE INDEX IDX_7C91E13BF639F774 ON campaign_application (campaign_id)');
        $this->addSql('CREATE INDEX IDX_7C91E13B61220EA6 ON campaign_application (creator_id)');
        $this->addSql('CREATE TABLE campaign_offer (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, amount INTEGER NOT NULL, message CLOB NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, responded_at DATETIME DEFAULT NULL, application_id INTEGER NOT NULL, CONSTRAINT FK_67D3E3513E030ACD FOREIGN KEY (application_id) REFERENCES campaign_application (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_67D3E3513E030ACD ON campaign_offer (application_id)');
        $this->addSql('CREATE TABLE wave_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_wave_user_email ON wave_user (email)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__company AS SELECT id, slug, name, industry, logo_url, verified, translations FROM company');
        $this->addSql('DROP TABLE company');
        $this->addSql('CREATE TABLE company (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, name VARCHAR(120) NOT NULL, industry VARCHAR(100) NOT NULL, logo_url VARCHAR(500) DEFAULT NULL, verified BOOLEAN NOT NULL, translations CLOB DEFAULT \'{}\' NOT NULL, owner_id INTEGER DEFAULT NULL, CONSTRAINT FK_4FBF094F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES wave_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO company (id, slug, name, industry, logo_url, verified, translations) SELECT id, slug, name, industry, logo_url, verified, translations FROM __temp__company');
        $this->addSql('DROP TABLE __temp__company');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_slug ON company (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4FBF094F7E3C61F9 ON company (owner_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__creator AS SELECT id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations FROM creator');
        $this->addSql('DROP TABLE creator');
        $this->addSql('CREATE TABLE creator (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, display_name VARCHAR(120) NOT NULL, category VARCHAR(80) NOT NULL, location VARCHAR(120) NOT NULL, bio CLOB NOT NULL, avatar_url VARCHAR(500) DEFAULT NULL, social_profiles CLOB NOT NULL, tags CLOB NOT NULL, translations CLOB DEFAULT \'{}\' NOT NULL, owner_id INTEGER DEFAULT NULL, CONSTRAINT FK_BC06EA637E3C61F9 FOREIGN KEY (owner_id) REFERENCES wave_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO creator (id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations) SELECT id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations FROM __temp__creator');
        $this->addSql('DROP TABLE __temp__creator');
        $this->addSql('CREATE UNIQUE INDEX uniq_creator_slug ON creator (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BC06EA637E3C61F9 ON creator (owner_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE campaign_application');
        $this->addSql('DROP TABLE campaign_offer');
        $this->addSql('DROP TABLE wave_user');
        $this->addSql('CREATE TEMPORARY TABLE __temp__company AS SELECT id, slug, name, industry, logo_url, verified, translations FROM company');
        $this->addSql('DROP TABLE company');
        $this->addSql('CREATE TABLE company (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, name VARCHAR(120) NOT NULL, industry VARCHAR(100) NOT NULL, logo_url VARCHAR(500) DEFAULT NULL, verified BOOLEAN NOT NULL, translations CLOB DEFAULT \'{}\' NOT NULL)');
        $this->addSql('INSERT INTO company (id, slug, name, industry, logo_url, verified, translations) SELECT id, slug, name, industry, logo_url, verified, translations FROM __temp__company');
        $this->addSql('DROP TABLE __temp__company');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_slug ON company (slug)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__creator AS SELECT id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations FROM creator');
        $this->addSql('DROP TABLE creator');
        $this->addSql('CREATE TABLE creator (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, display_name VARCHAR(120) NOT NULL, category VARCHAR(80) NOT NULL, location VARCHAR(120) NOT NULL, bio CLOB NOT NULL, avatar_url VARCHAR(500) DEFAULT NULL, social_profiles CLOB NOT NULL, tags CLOB NOT NULL, translations CLOB DEFAULT \'{}\' NOT NULL)');
        $this->addSql('INSERT INTO creator (id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations) SELECT id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations FROM __temp__creator');
        $this->addSql('DROP TABLE __temp__creator');
        $this->addSql('CREATE UNIQUE INDEX uniq_creator_slug ON creator (slug)');
    }
}
