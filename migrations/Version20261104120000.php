<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record where creator requests come from (profile, services page, homepage row).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE creator_inquiry ADD COLUMN IF NOT EXISTS source VARCHAR(20) DEFAULT 'profile' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creator_inquiry DROP COLUMN IF EXISTS source');
    }
}
