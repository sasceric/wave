<?php

declare(strict_types=1);

namespace App\Migration;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

abstract class LegacySqlMigration extends AbstractMigration
{
    /**
     * @var array<string, true>
     */
    private array $identityTables = [];

    protected function addSql(string $sql, array $params = [], array $types = []): void
    {
        if (!$this->isPostgreSQL()) {
            parent::addSql($sql, $params, $types);

            return;
        }

        $identityTable = LegacySqlCompatibility::identityTableForCreateStatement($sql);
        $sql = LegacySqlCompatibility::forPostgreSql($sql);
        parent::addSql($sql, $params, $types);

        if ($identityTable !== null) {
            $this->identityTables[$identityTable] = true;
        }

        if (preg_match('/^\s*INSERT\s+INTO\s+([a-z_][a-z0-9_]*)\s*\(\s*id\b/i', $sql, $insertMatch) === 1
            && isset($this->identityTables[strtolower($insertMatch[1])])
        ) {
            parent::addSql(LegacySqlCompatibility::identityResetSql(strtolower($insertMatch[1])));
        }
    }

    protected function isPostgreSQL(): bool
    {
        return $this->platform instanceof PostgreSQLPlatform;
    }
}
