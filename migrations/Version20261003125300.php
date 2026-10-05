<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

final class Version20261003125300 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Add featured company curation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD featured BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP COLUMN featured');
    }
}
