<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class MaintenanceMode
{
    public function __construct(private readonly Connection $connection) {}

    public function enabled(): bool
    {
        return (bool) $this->connection->fetchOne('SELECT maintenance_enabled FROM platform_settings WHERE id = 1');
    }

    public function setEnabled(bool $enabled): void
    {
        $this->connection->executeStatement(
            'INSERT INTO platform_settings (id, maintenance_enabled) VALUES (1, ?) ON CONFLICT (id) DO UPDATE SET maintenance_enabled = EXCLUDED.maintenance_enabled',
            [$enabled], [ParameterType::BOOLEAN],
        );
    }
}
