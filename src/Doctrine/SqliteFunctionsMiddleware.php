<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Production runs on PostgreSQL; the test suite also runs on SQLite. SQLite's LOWER() only
 * folds ASCII, so "Čačak" would never match "čačak". This adds a Unicode-aware wave_lower()
 * on SQLite connections so the same queries behave the same on both databases.
 */
#[AsMiddleware]
final class SqliteFunctionsMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);
                $native = $connection->getNativeConnection();
                $lower = static fn (?string $value): ?string => $value === null ? null : mb_strtolower($value);
                if ($native instanceof \Pdo\Sqlite) {
                    $native->createFunction('wave_lower', $lower, 1, \PDO::SQLITE_DETERMINISTIC);
                } elseif ($native instanceof \PDO && $native->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $native->sqliteCreateFunction('wave_lower', $lower, 1);
                }

                return $connection;
            }
        };
    }
}
