<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store campaign application totals and backfill genuine proposals, excluding invitation-based hires.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign ADD COLUMN application_count INTEGER DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE campaign SET application_count = (
            SELECT COUNT(*) FROM campaign_application a
            WHERE a.campaign_id = campaign.id AND NOT EXISTS (
                SELECT 1 FROM campaign_invitation i
                WHERE i.campaign_id = a.campaign_id AND i.creator_id = a.creator_id
                  AND i.status <> 'declined'
            )
        )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign DROP COLUMN application_count');
    }
}
