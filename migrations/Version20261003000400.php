<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add creator-selected categories and creator-authored FAQs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE creator ADD COLUMN categories CLOB DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE creator ADD COLUMN faqs CLOB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creator DROP categories');
        $this->addSql('ALTER TABLE creator DROP faqs');
    }
}
