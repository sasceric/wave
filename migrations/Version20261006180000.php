<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261006180000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Index image dimensions for responsive media thumbnails.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD width INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD height INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP COLUMN width');
        $this->addSql('ALTER TABLE media DROP COLUMN height');
    }
}
