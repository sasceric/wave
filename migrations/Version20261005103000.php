<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add external OAuth identities and preserve account email language.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wave_user ADD preferred_locale VARCHAR(5) DEFAULT 'bs' NOT NULL");
        $this->addSql('CREATE TABLE oauth_identity (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, provider VARCHAR(20) NOT NULL, subject VARCHAR(255) NOT NULL, CONSTRAINT FK_4AE96AD8A76ED395 FOREIGN KEY (user_id) REFERENCES wave_user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_provider_subject ON oauth_identity (provider, subject)');
        $this->addSql('CREATE INDEX IDX_4AE96AD8A76ED395 ON oauth_identity (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE oauth_identity');
        $this->addSql('ALTER TABLE wave_user DROP COLUMN preferred_locale');
    }
}
