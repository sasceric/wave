<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional campaign cover images.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign ADD cover_media_id INTEGER DEFAULT NULL REFERENCES media (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign DROP COLUMN cover_media_id');
    }
}
