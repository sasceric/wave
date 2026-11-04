<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the "Partner Wave.ba" flag for companies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD COLUMN IF NOT EXISTS partner BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP COLUMN IF EXISTS partner');
    }
}
