<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Identify voluntary campaigns explicitly while preserving all existing paid campaigns.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE campaign ADD compensation_type VARCHAR(16) DEFAULT 'paid' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Voluntary collaboration terms must remain identifiable in historical records.');
    }
}
