<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Connection;

use JardisAdapter\DbConnection\Config\SqliteConfig;
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
        $this->createPdoConnection();
        $this->applySqliteOptimizations();
    }

    public function reconnect(): void
    {
        $this->disconnect();
        $this->createPdoConnection();
        $this->applySqliteOptimizations();
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
            throw new RuntimeException(
                sprintf('SQLite database directory is not writable: %s', $directory)
            );
        }

        if (file_exists($dbPath) && !is_readable($dbPath)) {
            throw new RuntimeException(
                sprintf('SQLite database file is not readable: %s', $dbPath)
            );
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
     */
    public function getServerVersion(): string
    {
        $statement = $this->pdo()->query('SELECT sqlite_version()');
        $result = $statement ? $statement->fetch(\PDO::FETCH_NUM) : null;
        return (string) ($result[0] ?? 'unknown');
    }

    public function getDatabasePath(): string
    {
        /** @var SqliteConfig $config */
        $config = $this->config;
        return $config->path;
    }

    /**
     * Executes VACUUM on the database (compression).
     */
    public function vacuum(): void
    {
        $this->pdo()->exec('VACUUM');
    }
}
