<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quoted replies and heart reactions to campaign and inquiry messages.';
    }

    public function up(Schema $schema): void
    {
        $sqlite = $this->platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
        foreach (['campaign_message', 'inquiry_message'] as $table) {
            $this->addSql("ALTER TABLE $table ADD COLUMN hearts JSON NOT NULL DEFAULT '[]'");
            if ($sqlite) {
                $this->addSql("ALTER TABLE $table ADD COLUMN reply_to_id INTEGER DEFAULT NULL REFERENCES $table (id) ON DELETE SET NULL");
            } else {
                $this->addSql("ALTER TABLE $table ADD COLUMN reply_to_id INT DEFAULT NULL");
                $this->addSql("ALTER TABLE $table ADD CONSTRAINT fk_{$table}_reply FOREIGN KEY (reply_to_id) REFERENCES $table (id) ON DELETE SET NULL");
            }
            $this->addSql("CREATE INDEX idx_{$table}_reply ON $table (reply_to_id)");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['campaign_message', 'inquiry_message'] as $table) {
            $this->addSql("DROP INDEX idx_{$table}_reply");
            if (!$this->platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
                $this->addSql("ALTER TABLE $table DROP CONSTRAINT fk_{$table}_reply");
            }
            $this->addSql("ALTER TABLE $table DROP COLUMN reply_to_id");
            $this->addSql("ALTER TABLE $table DROP COLUMN hearts");
        }
    }
}
