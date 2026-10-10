<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep anonymous account references for shared history and persist file cleanup after deletion.';
    }

    public function up(Schema $schema): void
    {
        $type = $this->platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform ? 'DATETIME' : 'TIMESTAMP(0) WITHOUT TIME ZONE';
        $this->addSql('ALTER TABLE wave_user ADD COLUMN deleted_at ' . $type . ' DEFAULT NULL');
        $this->addSql('CREATE TABLE stored_file_deletion (id VARCHAR(64) NOT NULL, kind VARCHAR(16) NOT NULL, path VARCHAR(500) NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stored_file_deletion');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN deleted_at');
    }
}
