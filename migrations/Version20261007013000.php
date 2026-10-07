<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261007013000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Add company cover media and indexed multiple industries, preserving existing industry values.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD COLUMN cover_media_id INTEGER DEFAULT NULL REFERENCES media (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_company_cover_media ON company (cover_media_id)');
        $this->addSql('CREATE TABLE company_industry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, company_id INTEGER NOT NULL REFERENCES company (id) ON DELETE CASCADE, value VARCHAR(100) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_industry ON company_industry (company_id, value)');
        $this->addSql('CREATE INDEX idx_company_industry_value ON company_industry (value, company_id)');
        $this->addSql("INSERT INTO company_industry (company_id, value) SELECT id, industry FROM company WHERE industry <> ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE company_industry');
        $this->addSql('DROP INDEX idx_company_cover_media');
        $this->addSql('ALTER TABLE company DROP COLUMN cover_media_id');
    }
}
