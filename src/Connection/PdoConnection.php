<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Connection;

use JardisAdapter\DbConnection\Data\DatabaseConfig;
use JardisAdapter\DbConnection\Data\MySqlConfig;
use JardisAdapter\DbConnection\Data\PostgresConfig;
use JardisAdapter\DbConnection\Data\SqliteConfig;
use JardisPort\DbConnection\DbConnectionInterface;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO connection management.
 * Base class for all database drivers providing common PDO functionality.
 */
class PdoConnection implements DbConnectionInterface
{
    protected ?PDO $pdo = null;
    protected ?string $databaseName = null;
    protected DatabaseConfig $config;

    public function __construct(DatabaseConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Creates the PDO connection with the given parameters.
     *
     * @param string $dsn The Data Source Name
     * @param DatabaseConfig $config The database configuration
     * @param string $databaseName Name of the database
     * @throws RuntimeException On connection error
     */
    protected function connect(
        string $dsn,
        DatabaseConfig $config,
        string $databaseName
    ): void {
        try {
            $user = null;
            $password = null;
            $options = DbConnectionInterface::DEFAULT_OPTIONS;

            if ($config instanceof MySqlConfig || $config instanceof PostgresConfig) {
                $user = $config->user;
                $password = $config->password;
                $options = array_replace(DbConnectionInterface::DEFAULT_OPTIONS, $config->options);
            } elseif ($config instanceof SqliteConfig) {
                $options = array_replace(DbConnectionInterface::DEFAULT_OPTIONS, $config->options);
            }

            $this->pdo = new PDO(
                $dsn,
                $user,
                $password,
                $options
            );
            $this->databaseName = $databaseName;
        } catch (PDOException $e) {
            throw new RuntimeException(
                sprintf(
                    'Could not connect to %s database: %s',
                    $config->getDriverName(),
                    $e->getMessage()
                ),
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('No active database connection');
        }

        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->databaseName = null;
    }

    public function beginTransaction(): void
    {
        try {
            $this->pdo()->beginTransaction();
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Failed to begin transaction: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function commit(): void
    {
        try {
            $this->pdo()->commit();
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Failed to commit transaction: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function rollback(): void
    {
        try {
            $this->pdo()->rollBack();
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Failed to rollback transaction: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    public function getDatabaseName(): string
    {
        if ($this->databaseName === null) {
            throw new RuntimeException('Database name not available');
        }

        return $this->databaseName;
    }

    public function getServerVersion(): string
    {
        if ($this->config->getDriverName() === 'sqlite') {
            $statement = $this->pdo()->query('SELECT sqlite_version()');
            $result = $statement ? $statement->fetch(PDO::FETCH_NUM) : null;
            return (string) ($result[0] ?? 'unknown');
        }

        return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function getDriverName(): string
    {
        return $this->config->getDriverName();
    }

    /**
     * Builds the DSN string for the connection.
     * Must be implemented by child classes.
     *
     * @throws RuntimeException If not implemented by child class
     */
    protected function buildDsn(): string
    {
        throw new RuntimeException('buildDsn() must be implemented by child class');
    }

    /**
     * Reconnects to the database.
     * Can be overridden by child classes for driver-specific reconnection logic.
     *
     * @throws RuntimeException On reconnection error
     */
    public function reconnect(): void
    {
        $this->disconnect();

        try {
            $this->connect($this->buildDsn(), $this->config, $this->databaseName ?? '');
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                sprintf('%s reconnection failed: %s', $this->getDriverName(), $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }
}
