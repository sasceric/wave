<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve offer revisions, track current application terms and add a delivery deadline.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_offer ADD due_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE campaign_application ADD offer_id INT DEFAULT NULL');
        $this->addSql('UPDATE campaign_application SET offer_id = (SELECT id FROM campaign_offer WHERE application_id = campaign_application.id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_application_current_offer ON campaign_application (offer_id)');
        $this->addSql('ALTER TABLE campaign_application ADD CONSTRAINT fk_application_current_offer FOREIGN KEY (offer_id) REFERENCES campaign_offer (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX UNIQ_67D3E3513E030ACD');
        $this->addSql('CREATE INDEX IDX_67D3E3513E030ACD ON campaign_offer (application_id)');
        $this->addSql("UPDATE campaign_application SET status = 'shortlisted' WHERE status = 'offer_declined'");
    }

    public function down(Schema $schema): void
    {
        // Revisions are financial history. Do not silently delete them to restore the old unique constraint.
        $this->throwIrreversibleMigrationException('Offer revisions must be preserved; restore a backup to roll back this migration.');
    }
}
