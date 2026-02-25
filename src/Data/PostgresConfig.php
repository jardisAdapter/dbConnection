<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Data;

/**
 * Configuration for PostgreSQL database connections.
 */
final readonly class PostgresConfig implements DatabaseConfig
{
    /**
     * @param string $host The hostname or IP address of the PostgreSQL server
     * @param string $user The username for the connection
     * @param string $password The password for the connection
     * @param string $database The name of the PostgreSQL database
     * @param int $port The port of the PostgreSQL server (default: 5432)
     * @param array<int, mixed> $options Additional PDO options
     */
    public function __construct(
        public string $host,
        public string $user,
        public string $password,
        public string $database,
        public int $port = 5432,
        public array $options = []
    ) {
    }

    public function getDriverName(): string
    {
        return 'pgsql';
    }
}
