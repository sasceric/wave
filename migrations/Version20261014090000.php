<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add optional creator presentation settings without changing profile approval requirements.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE creator ADD COLUMN presentation ".$this->platform->getJsonTypeDeclarationSQL([])." DEFAULT '{}' NOT NULL");
        $this->addSql('CREATE TABLE creator_profile_visit (creator_id INT NOT NULL, day DATE NOT NULL, views INT NOT NULL DEFAULT 0, PRIMARY KEY(creator_id, day), FOREIGN KEY(creator_id) REFERENCES creator(id) ON DELETE CASCADE)');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE creator_profile_visit'); $this->addSql('ALTER TABLE creator DROP COLUMN presentation'); }
}
