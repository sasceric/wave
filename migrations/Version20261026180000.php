<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow optional expiry for manually featured creator and company profiles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creator ADD featured_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE company ADD featured_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT (SELECT COUNT(*) FROM creator WHERE featured_until IS NOT NULL) + (SELECT COUNT(*) FROM company WHERE featured_until IS NOT NULL)') > 0, 'Remove or resolve featured expiry dates before dropping them.');
        $this->addSql('ALTER TABLE creator DROP featured_until');
        $this->addSql('ALTER TABLE company DROP featured_until');
    }
}
