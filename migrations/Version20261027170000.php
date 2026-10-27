<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optional featured positions for homepage rails and recommended directories.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE homepage_settings ADD companies_slider_enabled BOOLEAN DEFAULT FALSE NOT NULL');
        foreach (['creator', 'company', 'campaign'] as $table) {
            $this->addSql("ALTER TABLE $table ADD featured_position INT DEFAULT NULL");
            $this->addSql("CREATE INDEX idx_{$table}_featured_position ON $table (featured_position, id) WHERE featured = TRUE");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE homepage_settings DROP companies_slider_enabled');
        foreach (['creator', 'company', 'campaign'] as $table) {
            $this->abortIf((int) $this->connection->fetchOne("SELECT COUNT(*) FROM $table WHERE featured_position IS NOT NULL") > 0, 'Preserve configured featured positions.');
            $this->addSql("DROP INDEX idx_{$table}_featured_position");
            $this->addSql("ALTER TABLE $table DROP featured_position");
        }
    }
}
