<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Admin-controlled maintenance mode, disabled by default.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE platform_settings (id INT NOT NULL, maintenance_enabled BOOLEAN DEFAULT FALSE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO platform_settings (id, maintenance_enabled) VALUES (1, FALSE)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((bool) $this->connection->fetchOne('SELECT maintenance_enabled FROM platform_settings WHERE id = 1'), 'Turn off maintenance mode before removing its configuration.');
        $this->addSql('DROP TABLE platform_settings');
    }
}
