<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Connection;

use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO connection management.
 * Base class for all database drivers providing common PDO functionality.
 */
class PdoConnection implements DbConnectionInterface, SavepointInterface
{
    protected ?PDO $pdo = null;
    protected DatabaseConfigInterface $config;

    public function __construct(DatabaseConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * Idempotent — if already connected, this is a no-op.
     *
     * @throws RuntimeException On connection error
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $this->createPdoConnection();
    }

    /**
     * Creates the PDO connection from the config.
     *
     * @throws RuntimeException On connection error
     */
    protected function createPdoConnection(): void
    {
        try {
            $this->pdo = new PDO(
                $this->config->getDsn(),
                $this->config->getUser(),
                $this->config->getPassword(),
                array_replace(DbConnectionInterface::DEFAULT_OPTIONS, $this->config->getOptions())
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                sprintf(
                    'Could not connect to %s database: %s',
                    $this->config->getDriverName(),
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

    public function savepoint(string $name): void
    {
        $this->runSavepointStatement('SAVEPOINT', $name);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->runSavepointStatement('RELEASE SAVEPOINT', $name);
    }

    public function rollbackToSavepoint(string $name): void
    {
        $this->runSavepointStatement('ROLLBACK TO SAVEPOINT', $name);
    }

    /**
     * Same SQL form on MySQL/MariaDB, PostgreSQL and SQLite. The name is
     * interpolated, so it is validated first: identifier form, max 63 characters.
     *
     * @throws InvalidArgumentException If the name is not a valid identifier
     * @throws RuntimeException If no transaction is active or the statement fails
     */
    private function runSavepointStatement(string $statement, string $name): void
    {
        $isIdentifier = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1;
        if (!$isIdentifier || strlen($name) > SavepointInterface::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Invalid savepoint name "%s": expected [A-Za-z_][A-Za-z0-9_]* with at most %d characters',
                $name,
                SavepointInterface::MAX_NAME_LENGTH
            ));
        }

        $pdo = $this->pdo();
        if (!$pdo->inTransaction()) {
            throw new RuntimeException(sprintf('%s %s requires an active transaction', $statement, $name));
        }

        try {
            $pdo->exec($statement . ' ' . $name);
        } catch (PDOException $e) {
            throw new RuntimeException(
                sprintf('%s %s failed: %s', $statement, $name, $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function getDatabaseName(): string
    {
        return $this->config->getDatabaseName();
    }

    public function getServerVersion(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function getDriverName(): string
    {
        return $this->config->getDriverName();
    }

    /**
     * Reconnects to the database.
     *
     * @throws RuntimeException On reconnection error
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->createPdoConnection();
    }
}
