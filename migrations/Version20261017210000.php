<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017210000 extends AbstractMigration
{
    public function getDescription(): string { return 'Preserve agency service labels as structured company service records.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE company ADD services JSON DEFAULT '[]' NOT NULL");
    }

    public function postUp(Schema $schema): void
    {
        // Read existing data only after Doctrine has executed the queued schema SQL.
        // Historical migration planning intentionally has no live application tables.
        foreach ($this->connection->fetchAllAssociative('SELECT p.id, c.tags FROM company p JOIN creator c ON c.owner_id = p.owner_id WHERE c.agency_profile = TRUE') as $row) {
            $tags = json_decode($row['tags'], true, flags: JSON_THROW_ON_ERROR);
            $services = [];
            foreach ($tags as $index => $title) {
                $services[] = ['id' => 'legacy-'.substr(hash('sha256', $index.':'.$title), 0, 20), 'title' => $title, 'description' => '', 'deliverables' => [], 'platforms' => [], 'engagement' => 'both'];
            }
            $this->connection->executeStatement('UPDATE company SET services = ? WHERE id = ?', [json_encode($services, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne("SELECT COUNT(*) FROM company WHERE CAST(services AS TEXT) <> '[]'") > 0, 'Agency services exist; preserve them instead of dropping this column.');
        $this->addSql('ALTER TABLE company DROP COLUMN services');
    }
}
