<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Config;

use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;

/**
 * Configuration for SQLite database connections.
 */
final readonly class SqliteConfig implements DatabaseConfigInterface
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

    public function getDsn(): string
    {
        return sprintf('sqlite:%s', $this->path);
    }

    public function getUser(): ?string
    {
        return null;
    }

    public function getPassword(): ?string
    {
        return null;
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
        return basename($this->path);
    }

    public function getDriverName(): string
    {
        return 'sqlite';
    }
}
