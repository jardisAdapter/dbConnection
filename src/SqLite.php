<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection;

use JardisAdapter\DbConnection\Data\SqliteConfig;
use JardisAdapter\DbConnection\Connection\PdoConnection;
use RuntimeException;

/**
 * SQLite database connection.
 */
final class SqLite extends PdoConnection
{
    /**
     * @param SqliteConfig $config The SQLite connection configuration
     * @throws RuntimeException On connection error
     */
    public function __construct(SqliteConfig $config)
    {
        parent::__construct($config);

        $this->validateDatabasePath($config->path);

        $this->connect($this->buildDsn(), $config, basename($config->path));

        $this->applySqliteOptimizations();
    }

    protected function buildDsn(): string
    {
        assert($this->config instanceof SqliteConfig);

        return sprintf('sqlite:%s', $this->config->path);
    }

    public function reconnect(): void
    {
        assert($this->config instanceof SqliteConfig);

        $config = $this->config;
        $this->disconnect();

        try {
            $this->connect($this->buildDsn(), $config, basename($config->path));
            $this->applySqliteOptimizations();
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                sprintf('SQLite reconnection failed: %s', $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Validates that the database path is accessible.
     *
     * @throws RuntimeException If the path is invalid or inaccessible
     */
    private function validateDatabasePath(string $dbPath): void
    {
        if ($dbPath === ':memory:') {
            return;
        }

        $directory = dirname($dbPath);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Could not connect to sqlite database');
        }

        if (file_exists($dbPath) && !is_readable($dbPath)) {
            throw new RuntimeException('Could not connect to sqlite database');
        }
    }

    /**
     * Applies SQLite-specific performance optimizations.
     */
    private function applySqliteOptimizations(): void
    {
        $this->pdo()->exec('PRAGMA foreign_keys = ON');
        $this->pdo()->exec('PRAGMA journal_mode = WAL');
        $this->pdo()->exec('PRAGMA synchronous = NORMAL');
        $this->pdo()->exec('PRAGMA temp_store = MEMORY');
        $this->pdo()->exec('PRAGMA mmap_size = 30000000000');
    }

    /**
     * Returns the file path of the SQLite database.
     * SQLite-specific method.
     */
    public function getDatabasePath(): string
    {
        assert($this->config instanceof SqliteConfig);

        return $this->config->path;
    }

    /**
     * Executes VACUUM on the database (compression).
     * SQLite-specific maintenance function.
     */
    public function vacuum(): void
    {
        $this->pdo()->exec('VACUUM');
    }
}
