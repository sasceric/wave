<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index featured-first campaign listings by newest publication date.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_campaign_directory_latest ON campaign (status, featured, published_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_campaign_directory_latest');
    }
}
