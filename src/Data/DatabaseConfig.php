<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Data;

/**
 * Interface for database configuration DTOs.
 */
interface DatabaseConfig
{
    /**
     * Returns the driver name for this configuration.
     *
     * @return string The driver name (e.g., 'mysql', 'pgsql', 'sqlite')
     */
    public function getDriverName(): string;
}
