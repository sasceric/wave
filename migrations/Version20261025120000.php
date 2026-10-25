<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email preferences (profile tips, Wave news) and profile reminder series state to accounts.';
    }

    public function up(Schema $schema): void
    {
        $date = $this->platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform ? 'DATETIME' : 'TIMESTAMP(0) WITHOUT TIME ZONE';
        $this->addSql('ALTER TABLE wave_user ADD COLUMN profile_tips_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE wave_user ADD COLUMN newsletter_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE wave_user ADD COLUMN profile_reminder_stage SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE wave_user ADD COLUMN profile_reminder_next_at '.$date.' DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_wave_user_profile_reminder ON wave_user (profile_reminder_stage, profile_reminder_next_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_wave_user_profile_reminder');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN profile_reminder_next_at');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN profile_reminder_stage');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN newsletter_enabled');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN profile_tips_enabled');
    }
}
