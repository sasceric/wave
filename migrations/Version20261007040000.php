<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261007040000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Add an optional private birthday to creator accounts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creator ADD COLUMN birthday DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creator DROP COLUMN birthday');
    }
}
