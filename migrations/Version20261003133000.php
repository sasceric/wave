<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

final class Version20261003133000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Require admin approval for newly registered accounts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user ADD approved BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user DROP approved');
    }
}
