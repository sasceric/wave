<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018230000 extends AbstractMigration
{
    public function getDescription(): string { return 'Track submission, requested changes and completion for each hired collaboration.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE campaign_offer ADD work_status VARCHAR(24) DEFAULT 'in_progress' NOT NULL");
        $this->addSql('ALTER TABLE campaign_offer ADD work_submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE campaign_offer ADD work_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE campaign_offer ADD work_changes_reason TEXT DEFAULT NULL');
        $this->addSql("UPDATE campaign_offer SET work_status = 'completed', work_completed_at = (SELECT c.finished_at FROM campaign c JOIN campaign_application a ON a.campaign_id = c.id WHERE a.id = campaign_offer.application_id) WHERE status = 'accepted' AND EXISTS (SELECT 1 FROM campaign_application a JOIN campaign c ON c.id = a.campaign_id WHERE a.id = campaign_offer.application_id AND c.status = 'finished')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Work completion and revision history must be retained.');
    }
}
