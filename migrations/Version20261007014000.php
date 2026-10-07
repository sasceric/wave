<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261007014000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Remove duplicate creator location; use account city and preserve standalone catalog cities.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE wave_user SET city = (SELECT SUBSTR(c.location, 1, 70) FROM creator c WHERE c.owner_id = wave_user.id) WHERE (city IS NULL OR TRIM(city) = '') AND EXISTS (SELECT 1 FROM creator c WHERE c.owner_id = wave_user.id AND TRIM(c.location) <> '')");
        $this->addSql('ALTER TABLE creator ADD COLUMN city VARCHAR(120) DEFAULT NULL');
        $this->addSql('UPDATE creator SET city = location WHERE owner_id IS NULL');
        $this->addSql('ALTER TABLE creator DROP COLUMN location');
        $this->addSql("DELETE FROM directory_index WHERE kind = 'creator'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE creator ADD COLUMN location VARCHAR(120) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE creator SET location = COALESCE((SELECT u.city FROM wave_user u WHERE u.id = creator.owner_id), city, '')");
        $this->addSql('ALTER TABLE creator DROP COLUMN city');
        $this->addSql("DELETE FROM directory_index WHERE kind = 'creator'");
    }
}
