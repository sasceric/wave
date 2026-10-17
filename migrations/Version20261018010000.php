<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Transactional Meilisearch invalidation fence for public search documents.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE search_index_change (id VARCHAR(64) NOT NULL, kind VARCHAR(20) NOT NULL, entity_id INT NOT NULL, revision VARCHAR(32) NOT NULL, PRIMARY KEY(id))');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE search_index_change'); }
}
