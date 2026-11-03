<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair campaign city storage for installations that already applied the text-only migration.';
    }

    public function up(Schema $schema): void
    {
        // Accept VARCHAR/TEXT upgrades and leave already-converted lists intact.
        $this->addSql(<<<'SQL'
DO $migration$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'campaign' AND column_name = 'city') THEN
        RETURN;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'campaign' AND column_name = 'city' AND data_type NOT IN ('json', 'jsonb')) THEN
        ALTER TABLE campaign ALTER COLUMN city DROP DEFAULT;
        ALTER TABLE campaign ALTER COLUMN city TYPE JSON USING (
            CASE WHEN city IS NULL OR lower(btrim(city, E' \t\n\r')) IN ('', 'svi', 'sve', 'svi gradovi', 'svi gradovi u bih', 'cijela bih', 'cela bih', 'cijela bosna i hercegovina', 'bih', 'all', 'all cities', 'alle', 'vsi', 'vsa mesta')
                THEN '[]'::json
                ELSE to_json(array_remove(regexp_split_to_array(btrim(city, E' \t\n\r'), '[[:space:]]*,[[:space:]]*'), ''))
            END
        );
    ELSIF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'campaign' AND column_name = 'city' AND data_type = 'jsonb') THEN
        ALTER TABLE campaign ALTER COLUMN city TYPE JSON USING city::json;
    END IF;

    ALTER TABLE campaign ALTER COLUMN city SET DEFAULT '[]'::json;
END;
$migration$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Campaign city lists cannot be safely restored to the original free-text values.');
    }
}
