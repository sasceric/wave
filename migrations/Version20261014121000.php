<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014121000 extends AbstractMigration
{
    public function getDescription(): string { return 'Keep showcase campaign upserts stable after public URL changes.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign ADD COLUMN presentation_demo_key VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_campaign_presentation_demo_key ON campaign (presentation_demo_key)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_campaign_presentation_demo_key');
        $this->addSql('ALTER TABLE campaign DROP COLUMN presentation_demo_key');
    }
}
