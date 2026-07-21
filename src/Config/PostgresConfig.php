<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Config;

use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;

/**
 * Configuration for PostgreSQL database connections.
 */
final readonly class PostgresConfig implements DatabaseConfigInterface
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

    public function getDsn(): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->host,
            $this->port,
            $this->database
        );
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @return array<int, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getDatabaseName(): string
    {
        return $this->database;
    }

    public function getDriverName(): string
    {
        return 'pgsql';
    }
}
