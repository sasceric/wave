<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record edits and soft deletions of chat messages.';
    }

    public function up(Schema $schema): void
    {
        $date = $this->platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform ? 'DATETIME' : 'TIMESTAMP(0) WITHOUT TIME ZONE';
        foreach (['campaign_message', 'inquiry_message'] as $table) {
            $this->addSql("ALTER TABLE $table ADD COLUMN edited_at $date DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD COLUMN deleted_at $date DEFAULT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['campaign_message', 'inquiry_message'] as $table) {
            $this->addSql("ALTER TABLE $table DROP COLUMN deleted_at");
            $this->addSql("ALTER TABLE $table DROP COLUMN edited_at");
        }
    }
}
