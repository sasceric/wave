<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add private agency service participants and optional campaign proposal details.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creator ADD agency_profile BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("ALTER TABLE campaign_application ADD proposal JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM creator WHERE agency_profile = TRUE') > 0, 'Agency service profiles exist; preserve their data instead of removing the discriminator.');
        $this->addSql('ALTER TABLE campaign_application DROP COLUMN proposal');
        $this->addSql('ALTER TABLE creator DROP COLUMN agency_profile');
    }
}
