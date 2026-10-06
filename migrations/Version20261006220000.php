<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261006220000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Support directory keyset ordering and available campaign counts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_creator_directory_name ON creator (display_name, id)');
        $this->addSql('CREATE INDEX idx_campaign_directory_order ON campaign (status, featured DESC, closes_at ASC, id ASC)');
        $this->addSql('CREATE INDEX idx_campaign_company_available ON campaign (company_id, status, closes_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_creator_directory_name');
        $this->addSql('DROP INDEX idx_campaign_directory_order');
        $this->addSql('DROP INDEX idx_campaign_company_available');
    }
}
