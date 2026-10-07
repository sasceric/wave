<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261007050000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Replace campaign location with city and country; preserve existing locations and areas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE campaign ADD COLUMN city VARCHAR(120) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE campaign ADD COLUMN country_code VARCHAR(2) DEFAULT NULL');
        $this->addSql("ALTER TABLE campaign ADD COLUMN categories JSON NOT NULL DEFAULT '[]'");
        $this->addSql('UPDATE campaign SET city = location');
        // A frozen list for the legacy demo/catalog values. Unrecognized text stays in city.
        $countries = [
            'BA' => ['Bosnia and Herzegovina', 'Bosna i Hercegovina', 'Bosnia & Herzegovina', 'BiH'],
            'HR' => ['Croatia', 'Hrvatska'], 'RS' => ['Serbia', 'Srbija'],
            'SI' => ['Slovenia', 'Slovenija'], 'ME' => ['Montenegro', 'Crna Gora'],
            'MK' => ['North Macedonia', 'Sjeverna Makedonija'],
            'US' => ['United States', 'United States of America', 'Sjedinjene Američke Države', 'USA'],
            'GB' => ['United Kingdom', 'UK'], 'DE' => ['Germany', 'Njemačka'],
            'AT' => ['Austria', 'Austrija'], 'CA' => ['Canada', 'Kanada'],
        ];
        foreach ($countries as $code => $names) {
            foreach ($names as $name) {
                $name = str_replace("'", "''", $name);
                $suffixLength = mb_strlen($name) + 2;
                $this->addSql("UPDATE campaign SET country_code = '$code', city = '' WHERE LOWER(TRIM(location)) = LOWER('$name')");
                $this->addSql("UPDATE campaign SET country_code = '$code', city = TRIM(SUBSTR(location, 1, LENGTH(location) - $suffixLength)) WHERE LOWER(SUBSTR(location, LENGTH(location) - $suffixLength + 1)) = LOWER(', $name')");
            }
        }
        // The existing primary category remains the fallback for migrated rows.
        $this->addSql('ALTER TABLE campaign DROP COLUMN location');
        $this->addSql("DELETE FROM directory_index WHERE kind = 'campaign'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE campaign ADD COLUMN location VARCHAR(120) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE campaign SET location = CASE WHEN country_code IS NULL THEN city WHEN city = '' THEN country_code ELSE city || ', ' || country_code END");
        $this->addSql('ALTER TABLE campaign DROP COLUMN categories');
        $this->addSql('ALTER TABLE campaign DROP COLUMN country_code');
        $this->addSql('ALTER TABLE campaign DROP COLUMN city');
        $this->addSql("DELETE FROM directory_index WHERE kind = 'campaign'");
    }
}
