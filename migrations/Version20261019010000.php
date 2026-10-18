<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Track provider submissions, client revisions and completion for direct collaborations.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE inquiry_offer ADD work_status VARCHAR(24) DEFAULT 'in_progress' NOT NULL");
        $this->addSql('ALTER TABLE inquiry_offer ADD work_submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE inquiry_offer ADD work_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE inquiry_offer ADD work_changes_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Direct collaboration work history must be retained.');
    }
}
