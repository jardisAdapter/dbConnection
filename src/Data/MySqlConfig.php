<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Data;

/**
 * Configuration for MySQL database connections.
 */
final readonly class MySqlConfig implements DatabaseConfig
{
    /**
     * @param string $host The hostname or IP address of the MySQL server
     * @param string $user The username for the connection
     * @param string $password The password for the connection
     * @param string $database The name of the MySQL database
     * @param int $port The port of the MySQL server (default: 3306)
     * @param string $charset The character set to use (default: utf8mb4)
     * @param array<int, mixed> $options Additional PDO options
     */
    public function __construct(
        public string $host,
        public string $user,
        public string $password,
        public string $database,
        public int $port = 3306,
        public string $charset = 'utf8mb4',
        public array $options = []
    ) {
    }

    public function getDriverName(): string
    {
        return 'mysql';
    }
}
