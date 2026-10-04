<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add owned media, media folders, and profile media associations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE media_folder (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(80) NOT NULL, name VARCHAR(120) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_media_folder_slug ON media_folder (slug)');
        $this->addSql("INSERT INTO media_folder (slug, name) VALUES ('creator-avatar', 'Creator avatars')");
        $this->addSql("INSERT INTO media_folder (slug, name) VALUES ('creator-portfolio', 'Creator portfolio')");
        $this->addSql("INSERT INTO media_folder (slug, name) VALUES ('company-logo', 'Company logos')");
        $this->addSql('CREATE TABLE media (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, folder_id INTEGER NOT NULL, owner_id INTEGER NOT NULL, original_name VARCHAR(255) NOT NULL, storage_path VARCHAR(500) NOT NULL, mime_type VARCHAR(100) NOT NULL, file_size INTEGER NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_MEDIA_FOLDER FOREIGN KEY (folder_id) REFERENCES media_folder (id) ON DELETE RESTRICT, CONSTRAINT FK_MEDIA_OWNER FOREIGN KEY (owner_id) REFERENCES wave_user (id) ON DELETE CASCADE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_media_storage_path ON media (storage_path)');
        $this->addSql('CREATE INDEX idx_media_owner_folder ON media (owner_id, folder_id)');
        $this->addSql('CREATE TABLE creator_portfolio_media (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, creator_id INTEGER NOT NULL, media_id INTEGER NOT NULL, position INTEGER NOT NULL, title VARCHAR(120) NOT NULL, platform VARCHAR(30) NOT NULL, CONSTRAINT FK_CREATOR_PORTFOLIO_CREATOR FOREIGN KEY (creator_id) REFERENCES creator (id) ON DELETE CASCADE, CONSTRAINT FK_CREATOR_PORTFOLIO_MEDIA FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_creator_portfolio_position ON creator_portfolio_media (creator_id, position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_creator_portfolio_media ON creator_portfolio_media (creator_id, media_id)');
        $this->addSql('ALTER TABLE creator ADD COLUMN avatar_media_id INTEGER DEFAULT NULL REFERENCES media (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_creator_avatar_media ON creator (avatar_media_id)');
        $this->addSql('ALTER TABLE company ADD COLUMN logo_media_id INTEGER DEFAULT NULL REFERENCES media (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_company_logo_media ON company (logo_media_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_company_logo_media');
        $this->addSql('ALTER TABLE company DROP COLUMN logo_media_id');
        $this->addSql('DROP INDEX idx_creator_avatar_media');
        $this->addSql('ALTER TABLE creator DROP COLUMN avatar_media_id');
        $this->addSql('DROP TABLE creator_portfolio_media');
        $this->addSql('DROP TABLE media');
        $this->addSql('DROP TABLE media_folder');
    }
}
