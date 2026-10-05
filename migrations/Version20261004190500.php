<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

final class Version20261004190500 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Store private account phone numbers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user ADD phone VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user DROP COLUMN phone');
    }
}
