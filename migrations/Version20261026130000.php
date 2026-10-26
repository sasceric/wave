<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep a short history of profile reminder emails (stage, time, manual) for administrators.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user ADD COLUMN profile_reminder_log JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user DROP COLUMN profile_reminder_log');
    }
}
