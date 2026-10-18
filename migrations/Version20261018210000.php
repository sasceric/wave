<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018210000 extends AbstractMigration
{
    public function getDescription(): string { return 'Retain reasons for campaign and individual collaboration cancellation.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_offer ADD cancellation_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE campaign ADD cancellation_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_offer DROP COLUMN cancellation_reason');
        $this->addSql('ALTER TABLE campaign DROP COLUMN cancellation_reason');
    }
}
