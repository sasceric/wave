<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261007170000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Add an account preference for in-app and push notifications.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('wave_user') && $schema->getTable('wave_user')->hasColumn('notifications_enabled')) {
            return;
        }
        $this->addSql('ALTER TABLE wave_user ADD COLUMN notifications_enabled BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user DROP COLUMN notifications_enabled');
    }
}
