<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store all package selections attached to a creator inquiry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE creator_inquiry ADD selected_packages CLOB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creator_inquiry DROP COLUMN selected_packages');
    }
}
