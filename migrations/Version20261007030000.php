<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261007030000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Add optional company social links for public profiles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE company ADD COLUMN social_links CLOB NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP COLUMN social_links');
    }
}
