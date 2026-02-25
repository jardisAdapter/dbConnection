<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection;

use JardisAdapter\DbConnection\Connection\PdoConnection;
use JardisAdapter\DbConnection\Data\ExternalConfig;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Wraps an externally managed PDO connection.
 *
 * This class allows integration with legacy systems or frameworks that provide
 * their own PDO instances. It extends PdoConnection to inherit all transaction
 * and connection management features while bypassing the normal connection
 * creation process.
 *
 * Example usage:
 * ```php
 * $frameworkPdo = $framework->getDatabaseConnection();
 * $config = new ExternalConfig($frameworkPdo);
 * $connection = new External($config);
 *
 * // Use like any other connection
 * $connection->beginTransaction();
 * $users = $connection->pdo()->query('SELECT * FROM users')->fetchAll();
 * $connection->commit();
 * ```
 */
final class External extends PdoConnection
{
    /**
     * @param ExternalConfig $config The external connection configuration
     * @throws RuntimeException If database name detection fails
     */
    public function __construct(ExternalConfig $config)
    {
        parent::__construct($config);

        // Set the PDO instance directly without creating a new connection
        $this->pdo = $config->pdo;

        // Auto-detect database name from PDO
        $driverName = $config->getDriverName();
        $this->databaseName = $this->detectDatabaseName($config->pdo, $driverName);
    }

    /**
     * Not applicable for external connections.
     *
     * @throws RuntimeException Always throws exception as DSN cannot be built for external connections
     */
    protected function buildDsn(): string
    {
        throw new RuntimeException(
            'Cannot build DSN for externally managed connection. ' .
            'This connection wraps an existing PDO instance.'
        );
    }

    /**
     * Reconnection for externally managed connections.
     *
     * Performs a health check on the existing connection. If the connection is still
     * alive, it continues to use it. If the connection is dead, an exception is thrown
     * since we cannot recreate it (credentials are managed by the external system).
     *
     * @throws RuntimeException If the connection is dead and cannot be restored
     */
    public function reconnect(): void
    {
        // Perform a health check on the existing connection
        try {
            $stmt = $this->pdo()->query('SELECT 1');
            if ($stmt === false) {
                throw new RuntimeException(
                    'External connection is dead and cannot be restored. ' .
                    'The external system must provide a new connection.'
                );
            }
            // Connection is alive - continue using it
        } catch (\Exception $e) {
            // Catch any exception (PDOException from query, or RuntimeException from pdo())
            throw new RuntimeException(
                'External connection is dead and cannot be restored. ' .
                'The external system must provide a new connection.',
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Attempts to detect the database name from the PDO connection.
     *
     * @param PDO $pdo The PDO instance
     * @param string $driver The driver name
     * @return string The detected database name or 'unknown' if detection fails
     */
    private function detectDatabaseName(PDO $pdo, string $driver): string
    {
        try {
            if ($driver === 'mysql') {
                $stmt = $pdo->query('SELECT DATABASE()');
                $result = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
                return (string) ($result[0] ?? 'unknown');
            }

            if ($driver === 'pgsql') {
                $stmt = $pdo->query('SELECT current_database()');
                $result = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
                return (string) ($result[0] ?? 'unknown');
            }

            if ($driver === 'sqlite') {
                return ':memory: or file';
            }

            return 'unknown';
        } catch (PDOException $e) {
            return 'unknown';
        }
    }
}
