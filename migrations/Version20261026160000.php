<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record standalone newsletter consent and withdrawal without inferring historic consent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriber ADD COLUMN legal_records JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriber DROP COLUMN legal_records');
    }
}
