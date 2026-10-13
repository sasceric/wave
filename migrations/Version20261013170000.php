<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013170000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add company classification as brand or agency; existing companies default to brand.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE company ADD COLUMN company_type VARCHAR(20) DEFAULT 'brand' NOT NULL");
        $this->addSql('CREATE INDEX idx_company_type ON company (company_type)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_company_type');
        $this->addSql('ALTER TABLE company DROP COLUMN company_type');
    }
}
