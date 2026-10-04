<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BAM, EUR, and RSD currency support to campaign and creator pricing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE campaign ADD currency VARCHAR(3) DEFAULT 'BAM' NOT NULL");
        $this->addSql("ALTER TABLE creator_inquiry ADD listed_price_currency VARCHAR(3) DEFAULT 'BAM' NOT NULL");
        $this->addSql("ALTER TABLE creator_inquiry ADD currency VARCHAR(3) DEFAULT 'BAM' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creator_inquiry DROP COLUMN currency');
        $this->addSql('ALTER TABLE creator_inquiry DROP COLUMN listed_price_currency');
        $this->addSql('ALTER TABLE campaign DROP COLUMN currency');
    }
}
