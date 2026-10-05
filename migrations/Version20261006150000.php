<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an about section to company profiles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD about TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP about');
    }
}
