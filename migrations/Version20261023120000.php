<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make all existing creator and agency portfolio works public, preserving their content and media.';
    }

    public function up(Schema $schema): void
    {
        // Agency studios use the same creator.portfolio storage. Queue SQL so
        // migration planning and dry runs never publish works as a side effect.
        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql(<<<'SQL'
                UPDATE creator
                SET portfolio = (
                    SELECT jsonb_agg(
                        CASE WHEN jsonb_typeof(work.value) = 'object'
                            THEN jsonb_set(work.value, '{publicPermission}', 'true'::jsonb)
                            ELSE work.value
                        END ORDER BY work.position
                    )
                    FROM jsonb_array_elements(portfolio::jsonb) WITH ORDINALITY AS work(value, position)
                )
                WHERE jsonb_typeof(portfolio::jsonb) = 'array' AND portfolio::jsonb <> '[]'::jsonb
                SQL);

            return;
        }

        $this->abortIf(!$this->platform instanceof SQLitePlatform, 'This migration supports PostgreSQL and SQLite.');
        $this->addSql(<<<'SQL'
            UPDATE creator
            SET portfolio = (
                SELECT json_group_array(json(
                    CASE WHEN work.type = 'object'
                        THEN json_set(work.value, '$.publicPermission', json('true'))
                        ELSE creator.portfolio -> work.fullkey
                    END
                ))
                FROM json_each(creator.portfolio) AS work
            )
            WHERE json_type(portfolio) = 'array' AND json_array_length(portfolio) > 0
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Previous individual portfolio visibility choices cannot be reconstructed.');
    }
}
