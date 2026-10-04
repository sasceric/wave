<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004191500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store private account city and country details.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user ADD city VARCHAR(70) DEFAULT NULL');
        $this->addSql('ALTER TABLE wave_user ADD country_code VARCHAR(2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_user DROP COLUMN country_code');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN city');
    }
}
