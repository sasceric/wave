<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Require provider consent before reopening cancelled campaign discussions.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_conversation ADD reopening_status VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE campaign_conversation ADD reopening_version INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE campaign_conversation ADD reopening_message TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Discussion consent history must be retained.');
    }
}
