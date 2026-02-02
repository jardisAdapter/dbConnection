<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection;

use JardisAdapter\DbConnection\Data\DatabaseConfig;
use JardisAdapter\DbConnection\Data\ConnectionPoolConfig;
use JardisAdapter\DbConnection\Data\MySqlConfig;
use JardisAdapter\DbConnection\Data\PostgresConfig;
use JardisAdapter\DbConnection\Data\SqliteConfig;
use JardisAdapter\DbConnection\Data\ExternalConfig;
use JardisPort\DbConnection\ConnectionPoolInterface;
use JardisPort\DbConnection\DbConnectionInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use InvalidArgumentException;
use PDO;

/**
 * ConnectionPool - Manages read/write splitting with load balancing
 *
 * This class provides connection pooling for database replication setups,
 * routing writes to a primary server and distributing reads across replica servers.
 * Optimized for PHP-FPM using PDO persistent connections.
 */
class ConnectionPool implements ConnectionPoolInterface
{
    private ?DbConnectionInterface $writerConnection = null;
    private DatabaseConfig $writerConfig;
    /** @var array<DatabaseConfig> */
    private array $readerConfigs = [];
    /** @var class-string<DbConnectionInterface> */
    private string $driverClass;
    /** @var array<DbConnectionInterface> */
    private array $readerConnections = [];
    private int $currentReaderIndex = 0;
    /** @var array<string, array{healthy: bool, timestamp: int}> */
    private array $healthCache = [];
    private ConnectionPoolConfig $config;
    /** @var array{reads: int, writes: int, failovers: int} */
    private array $stats = ['reads' => 0, 'writes' => 0, 'failovers' => 0];

    /**
     * @param DatabaseConfig $writer Configuration for the writer (primary) database
     * @param array<DatabaseConfig> $readers Reader configs (empty = use writer for reads)
     * @param class-string<DbConnectionInterface> $driverClass Driver class (e.g., MySql::class)
     * @param ConnectionPoolConfig|null $config Pool configuration
     * @throws InvalidArgumentException If driver class is invalid or reader configs don't match writer type
     */
    public function __construct(
        DatabaseConfig $writer,
        array $readers,
        string $driverClass,
        ?ConnectionPoolConfig $config = null
    ) {
        try {
            $reflection = new ReflectionClass($driverClass);
            if (!$reflection->implementsInterface(DbConnectionInterface::class)) {
                throw new InvalidArgumentException(
                    "Driver class must implement DbConnectionInterface: {$driverClass}"
                );
            }
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException("Invalid driver class: {$driverClass}", 0, $e);
        }

        // Validate that all readers have the same config type as writer
        $writerConfigClass = $writer::class;
        foreach ($readers as $index => $reader) {
            if ($reader::class !== $writerConfigClass) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Reader config at index %d (%s) does not match writer config type (%s). ' .
                        'All connections in a pool must use the same database engine.',
                        $index,
                        $reader::class,
                        $writerConfigClass
                    )
                );
            }
        }

        $this->config = $config ?? new ConnectionPoolConfig();
        $this->writerConfig = $writer;
        $this->readerConfigs = $readers;
        $this->driverClass = $driverClass;

        // Connections are created lazily on first use
    }

    /**
     * Get a connection for write operations (primary database)
     *
     * @throws RuntimeException If writer connection fails health check or creation fails
     */
    public function getWriter(): DbConnectionInterface
    {
        $this->stats['writes']++;

        // Lazy create writer connection on first use
        if ($this->writerConnection === null) {
            $this->writerConnection = $this->createConnection($this->writerConfig, $this->driverClass);
        }

        if ($this->config->validateConnections && !$this->isHealthy($this->writerConnection)) {
            throw new RuntimeException('Writer connection health check failed');
        }

        return $this->writerConnection;
    }

    /**
     * Get a connection for read operations (replica database)
     *
     * Automatically load-balances across available readers and performs
     * failover if a reader is unhealthy.
     *
     * @throws RuntimeException If all readers fail health checks or creation fails
     */
    public function getReader(): DbConnectionInterface
    {
        $this->stats['reads']++;

        // Lazy create reader connections on first use
        if (empty($this->readerConnections)) {
            if (empty($this->readerConfigs)) {
                // No readers configured - use writer as reader
                $this->readerConnections[] = $this->getWriter();
            } else {
                // Create all reader connections
                foreach ($this->readerConfigs as $readerConfig) {
                    $this->readerConnections[] = $this->createConnection($readerConfig, $this->driverClass);
                }
            }
        }

        $attempts = 0;
        $maxAttempts = min($this->config->maxRetries, count($this->readerConnections));

        while ($attempts < $maxAttempts) {
            $connection = $this->selectReader();

            if (!$this->config->validateConnections || $this->isHealthy($connection)) {
                return $connection;
            }

            $this->stats['failovers']++;
            $attempts++;
        }

        throw new RuntimeException(
            'All reader connections are unavailable after ' . $maxAttempts . ' attempts'
        );
    }

    /**
     * Get pool statistics
     *
     * @return array{reads: int, writes: int, failovers: int, readers: int}
     */
    public function getStats(): array
    {
        // Return configured reader count (not created connections count)
        $readerCount = empty($this->readerConfigs) ? 1 : count($this->readerConfigs);

        return [
            'reads' => $this->stats['reads'],
            'writes' => $this->stats['writes'],
            'failovers' => $this->stats['failovers'],
            'readers' => $readerCount,
        ];
    }

    /**
     * Reset statistics counters
     */
    public function resetStats(): void
    {
        $this->stats = ['reads' => 0, 'writes' => 0, 'failovers' => 0];
    }

    /**
     * Get all reader connections.
     *
     * Returns all configured reader connections without load-balancing.
     * If no readers are configured, returns an array containing only the writer connection.
     * Connections are created lazily on first call.
     *
     * @return array<DbConnectionInterface> All reader connections
     * @throws RuntimeException If connection creation fails
     */
    public function getReaders(): array
    {
        // Lazy create reader connections on first use (same logic as getReader())
        if (empty($this->readerConnections)) {
            if (empty($this->readerConfigs)) {
                // No readers configured - use writer as reader
                $this->readerConnections[] = $this->getWriter();
            } else {
                // Create all reader connections
                foreach ($this->readerConfigs as $readerConfig) {
                    $this->readerConnections[] = $this->createConnection($readerConfig, $this->driverClass);
                }
            }
        }

        return $this->readerConnections;
    }

    /**
     * Get the number of configured readers.
     *
     * Returns the count of configured reader databases (not created connections).
     * If no readers are configured, returns 1 (writer is used as reader).
     *
     * @return int Number of reader configurations
     */
    public function getReaderCount(): int
    {
        return empty($this->readerConfigs) ? 1 : count($this->readerConfigs);
    }

    /**
     * Create a database connection with proper configuration
     *
     * @param DatabaseConfig $config
     * @param class-string<DbConnectionInterface> $driverClass
     * @return DbConnectionInterface
     */
    private function createConnection(DatabaseConfig $config, string $driverClass): DbConnectionInterface
    {
        if ($this->config->usePersistent || $this->config->connectionTimeout !== 5) {
            $config = $this->mergeOptions($config);
        }

        return new $driverClass($config);
    }

    /**
     * Merge pool options into database config
     *
     * @param DatabaseConfig $config
     * @return DatabaseConfig
     */
    private function mergeOptions(DatabaseConfig $config): DatabaseConfig
    {
        // Get existing options based on config type
        $options = match (true) {
            $config instanceof MySqlConfig,
            $config instanceof PostgresConfig,
            $config instanceof SqliteConfig => $config->options,
            $config instanceof ExternalConfig => [],
            default => []
        };

        if ($this->config->usePersistent) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        $options[PDO::ATTR_TIMEOUT] = $this->config->connectionTimeout;

        return match (true) {
            $config instanceof MySqlConfig => new MySqlConfig(
                host: $config->host,
                user: $config->user,
                password: $config->password,
                database: $config->database,
                port: $config->port,
                charset: $config->charset,
                options: $options
            ),
            $config instanceof PostgresConfig => new PostgresConfig(
                host: $config->host,
                user: $config->user,
                password: $config->password,
                database: $config->database,
                port: $config->port,
                options: $options
            ),
            $config instanceof SqliteConfig => new SqliteConfig(
                path: $config->path,
                options: $options
            ),
            $config instanceof ExternalConfig => $config,
            default => throw new InvalidArgumentException('Unsupported database config type: ' . $config::class)
        };
    }

    /**
     * Select a reader connection based on load balancing strategy
     *
     * @return DbConnectionInterface
     */
    private function selectReader(): DbConnectionInterface
    {
        return match ($this->config->loadBalancingStrategy) {
            ConnectionPoolConfig::STRATEGY_RANDOM => $this->selectRandomReader(),
            ConnectionPoolConfig::STRATEGY_WEIGHTED => $this->selectWeightedReader(),
            default => $this->selectRoundRobinReader(),
        };
    }

    /**
     * Select reader using round-robin strategy
     *
     * @return DbConnectionInterface
     */
    private function selectRoundRobinReader(): DbConnectionInterface
    {
        $connection = $this->readerConnections[$this->currentReaderIndex];
        $this->currentReaderIndex = ($this->currentReaderIndex + 1) % count($this->readerConnections);
        return $connection;
    }

    /**
     * Select reader using random strategy
     *
     * @return DbConnectionInterface
     */
    private function selectRandomReader(): DbConnectionInterface
    {
        $index = array_rand($this->readerConnections);
        return $this->readerConnections[$index];
    }

    /**
     * Select reader using weighted strategy
     *
     * Note: Currently uses round-robin as weights are not yet configurable.
     * Future enhancement: Add weight configuration to DatabaseConfig.
     *
     * @return DbConnectionInterface
     */
    private function selectWeightedReader(): DbConnectionInterface
    {
        return $this->selectRoundRobinReader();
    }

    /**
     * Check if a connection is healthy
     *
     * Uses caching to avoid excessive health checks.
     *
     * @param DbConnectionInterface $connection
     * @return bool
     */
    private function isHealthy(DbConnectionInterface $connection): bool
    {
        $key = $this->getConnectionKey($connection);
        $now = time();

        if (isset($this->healthCache[$key])) {
            $cached = $this->healthCache[$key];
            if (($now - $cached['timestamp']) < $this->config->healthCheckCacheTtl) {
                return $cached['healthy'];
            }
        }

        $healthy = $this->performHealthCheck($connection);

        $this->healthCache[$key] = [
            'healthy' => $healthy,
            'timestamp' => $now,
        ];

        return $healthy;
    }

    /**
     * Perform actual health check on a connection
     *
     * Attempts to reconnect once if the initial check fails.
     *
     * @param DbConnectionInterface $connection
     * @return bool
     */
    private function performHealthCheck(DbConnectionInterface $connection): bool
    {
        try {
            $pdo = $connection->pdo();
            $stmt = $pdo->query('SELECT 1');
            return $stmt !== false;
        } catch (\Exception $e) {
            try {
                $connection->reconnect();
                $pdo = $connection->pdo();
                $stmt = $pdo->query('SELECT 1');
                return $stmt !== false;
            } catch (\Exception $reconnectException) {
                return false;
            }
        }
    }

    /**
     * Generate a unique key for a connection (for caching purposes)
     *
     * @param DbConnectionInterface $connection
     * @return string
     */
    private function getConnectionKey(DbConnectionInterface $connection): string
    {
        return spl_object_hash($connection);
    }
}
