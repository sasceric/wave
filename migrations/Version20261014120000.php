<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Identify the three fictional presentation accounts independently of editable emails and URLs.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user ADD COLUMN presentation_demo_key VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_presentation_demo_key ON wave_user (presentation_demo_key)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_presentation_demo_key');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN presentation_demo_key');
    }
}
