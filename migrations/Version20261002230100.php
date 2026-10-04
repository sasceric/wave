<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002230100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email verification state and one-time account action tokens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_action_token (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, purpose VARCHAR(20) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_38351185A76ED395 FOREIGN KEY (user_id) REFERENCES wave_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_user_action_token_user_purpose ON user_action_token (user_id, purpose)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_action_token_hash ON user_action_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_38351185A76ED395 ON user_action_token (user_id)');
        $this->addSql('ALTER TABLE wave_user ADD COLUMN email_verified BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_action_token');
        $this->addSql('CREATE TEMPORARY TABLE __temp__wave_user AS SELECT id, email, password, role, moderator FROM wave_user');
        $this->addSql('DROP TABLE wave_user');
        $this->addSql('CREATE TABLE wave_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL, moderator BOOLEAN DEFAULT 0 NOT NULL)');
        $this->addSql('INSERT INTO wave_user (id, email, password, role, moderator) SELECT id, email, password, role, moderator FROM __temp__wave_user');
        $this->addSql('DROP TABLE __temp__wave_user');
        $this->addSql('CREATE UNIQUE INDEX uniq_wave_user_email ON wave_user (email)');
    }
}
