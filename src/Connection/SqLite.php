<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Connection;

use JardisAdapter\DbConnection\Config\SqliteConfig;
use RuntimeException;

/**
 * SQLite database connection.
 *
 * Lazy like every PdoConnection: construction touches neither the file system
 * nor the database. The first call that needs the PDO handle (pdo(),
 * a transaction, a query) validates the path, opens the file and applies the
 * PRAGMAs; errors surface there. The PRAGMAs run inside createPdoConnection()
 * directly on the fresh handle (no pdo() round trip), so they are applied
 * after the first open and after every reopen (connect() after disconnect(),
 * reconnect()).
 */
final class SqLite extends PdoConnection
{
    private const IN_MEMORY = ':memory:';

    /** The path precondition is checked once, on the first open; later reopens fail as connection errors. */
    private bool $pathValidated = false;

    /**
     * @param SqliteConfig $config The SQLite connection configuration
     */
    public function __construct(SqliteConfig $config)
    {
        parent::__construct($config);
    }

    /**
     * An in-memory database lives exactly as long as its PDO handle: while one
     * is open, reconnect() keeps it (a new handle would be a new, empty
     * database). File databases are reopened as usual.
     *
     * @throws RuntimeException On reconnection error
     */
    public function reconnect(): void
    {
        if ($this->getDatabasePath() === self::IN_MEMORY && $this->isConnected()) {
            return;
        }

        parent::reconnect();
    }

    /**
     * Validates the path, opens the file and applies the PRAGMAs.
     *
     * @throws RuntimeException On invalid path, connection error or PRAGMA failure (previous: the PDOException);
     *                          the handle is discarded on a PRAGMA failure
     */
    protected function createPdoConnection(): void
    {
        if (!$this->pathValidated) {
            $this->validateDatabasePath($this->getDatabasePath());
            $this->pathValidated = true;
        }

        parent::createPdoConnection();

        try {
            $this->applySqliteOptimizations();
        } catch (\PDOException $e) {
            $this->pdo = null;
            throw new RuntimeException(
                'Could not apply SQLite PRAGMAs: ' . $e->getMessage(),
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
     * Applies SQLite-specific performance optimizations on the freshly opened
     * handle. Works on $this->pdo directly: pdo() would re-enter the lazy open.
     */
    private function applySqliteOptimizations(): void
    {
        $pdo = $this->pdo;
        if ($pdo === null) {
            return;
        }

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA mmap_size = 30000000000');
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
