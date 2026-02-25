<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Data;

/**
 * Configuration for SQLite database connections.
 */
final readonly class SqliteConfig implements DatabaseConfig
{
    /**
     * @param string $path The file path to the SQLite database (use ':memory:' for in-memory database)
     * @param array<int, mixed> $options Additional PDO options
     */
    public function __construct(
        public string $path,
        public array $options = []
    ) {
    }

    public function getDriverName(): string
    {
        return 'sqlite';
    }
}
