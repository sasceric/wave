<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Record support ticket status changes in the reply timeline.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_support_ticket_message ADD COLUMN event_status VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_support_ticket_message DROP COLUMN event_status');
    }
}
