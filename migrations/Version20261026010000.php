<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store versioned terms acceptance and newsletter choices; do not infer historic consent.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE wave_user ADD COLUMN legal_records JSON DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE wave_user DROP COLUMN legal_records'); }
}
