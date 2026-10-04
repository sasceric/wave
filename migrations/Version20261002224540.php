<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002224540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wave_user ADD COLUMN moderator BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__wave_user AS SELECT id, email, password, role FROM wave_user');
        $this->addSql('DROP TABLE wave_user');
        $this->addSql('CREATE TABLE wave_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL)');
        $this->addSql('INSERT INTO wave_user (id, email, password, role) SELECT id, email, password, role FROM __temp__wave_user');
        $this->addSql('DROP TABLE __temp__wave_user');
        $this->addSql('CREATE UNIQUE INDEX uniq_wave_user_email ON wave_user (email)');
    }
}
