<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection;

use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use RuntimeException;

/**
 * ConnectionPool - Manages read/write splitting with load balancing.
 *
 * Connections are injected from the outside via ConnectionFactory.
 * The pool orchestrates health checks, load balancing, and failover.
 */
class ConnectionPool implements ConnectionPoolInterface
{
    private DbConnectionInterface $writer;
    /** @var array<DbConnectionInterface> */
    private array $readers;
    private int $currentReaderIndex = 0;
    /** @var array<string, array{healthy: bool, timestamp: int}> */
    private array $healthCache = [];
    private ConnectionPoolConfig $config;
    /** @var array{reads: int, writes: int, failovers: int} */
    private array $stats = ['reads' => 0, 'writes' => 0, 'failovers' => 0];

    /**
     * @param DbConnectionInterface $writer Writer (primary) connection
     * @param array<DbConnectionInterface> $readers Reader connections (empty = use writer for reads)
     * @param ConnectionPoolConfig|null $config Pool configuration
     */
    public function __construct(
        DbConnectionInterface $writer,
        array $readers = [],
        ?ConnectionPoolConfig $config = null
    ) {
        $this->writer = $writer;
        $this->readers = array_values($readers);
        $this->config = $config ?? new ConnectionPoolConfig();
    }

    /**
     * Get a connection for write operations (primary database).
     *
     * @throws RuntimeException If writer connection fails health check
     */
    public function getWriter(): DbConnectionInterface
    {
        if ($this->config->validateConnections && !$this->isHealthy($this->writer)) {
            throw new RuntimeException('Writer connection health check failed');
        }

        $this->stats['writes']++;

        return $this->writer;
    }

    /**
     * Get a connection for read operations (replica database).
     *
     * Automatically load-balances across available readers and performs
     * failover if a reader is unhealthy. Each reader is tried at most once.
     *
     * @throws RuntimeException If all readers fail health checks
     */
    public function getReader(): DbConnectionInterface
    {
        $effectiveReaders = $this->getEffectiveReaders();
        /** @var array<string, true> $tried */
        $tried = [];

        while (count($tried) < count($effectiveReaders)) {
            $connection = $this->selectReader($effectiveReaders, $tried);

            if (!$this->config->validateConnections || $this->isHealthy($connection)) {
                $this->stats['reads']++;
                return $connection;
            }

            $this->stats['failovers']++;
            $tried[spl_object_hash($connection)] = true;
        }

        throw new RuntimeException(
            'All ' . count($effectiveReaders) . ' reader connections are unavailable'
        );
    }

    /**
     * @return array{reads: int, writes: int, failovers: int, readers: int}
     */
    public function getStats(): array
    {
        return [
            'reads' => $this->stats['reads'],
            'writes' => $this->stats['writes'],
            'failovers' => $this->stats['failovers'],
            'readers' => $this->getReaderCount(),
        ];
    }

    public function resetStats(): void
    {
        $this->stats = ['reads' => 0, 'writes' => 0, 'failovers' => 0];
    }

    /**
     * @return array<DbConnectionInterface>
     */
    public function getReaders(): array
    {
        return $this->getEffectiveReaders();
    }

    public function getReaderCount(): int
    {
        return empty($this->readers) ? 1 : count($this->readers);
    }

    /**
     * @return array<DbConnectionInterface>
     */
    private function getEffectiveReaders(): array
    {
        return empty($this->readers) ? [$this->writer] : $this->readers;
    }

    /**
     * @param array<DbConnectionInterface> $readers
     * @param array<string, true> $excluded
     */
    private function selectReader(array $readers, array $excluded = []): DbConnectionInterface
    {
        return match ($this->config->loadBalancingStrategy) {
            ConnectionPoolConfig::STRATEGY_RANDOM => $this->selectRandomReader($readers, $excluded),
            default => $this->selectRoundRobinReader($readers, $excluded),
        };
    }

    /**
     * Selects the next reader via round-robin over the stable full reader list.
     * Skips excluded (unhealthy) readers so the index stays consistent.
     *
     * @param array<DbConnectionInterface> $readers
     * @param array<string, true> $excluded
     */
    private function selectRoundRobinReader(array $readers, array $excluded = []): DbConnectionInterface
    {
        $count = count($readers);

        for ($i = 0; $i < $count; $i++) {
            $index = $this->currentReaderIndex % $count;
            $this->currentReaderIndex++;
            $connection = $readers[$index];

            if (!isset($excluded[spl_object_hash($connection)])) {
                return $connection;
            }
        }

        throw new RuntimeException('No reader candidates available');
    }

    /**
     * @param array<DbConnectionInterface> $readers
     * @param array<string, true> $excluded
     */
    private function selectRandomReader(array $readers, array $excluded = []): DbConnectionInterface
    {
        $candidates = array_values(
            array_filter($readers, static fn(DbConnectionInterface $r): bool => !isset($excluded[spl_object_hash($r)]))
        );

        if (empty($candidates)) {
            throw new RuntimeException('No reader candidates available');
        }

        return $candidates[array_rand($candidates)];
    }

    private function isHealthy(DbConnectionInterface $connection): bool
    {
        $key = spl_object_hash($connection);
        $now = time();

        if (isset($this->healthCache[$key])) {
            $cached = $this->healthCache[$key];
            $ttl = $cached['healthy']
                ? $this->config->healthCheckCacheTtl
                : $this->config->healthCheckNegativeCacheTtl;

            if ($ttl > 0 && ($now - $cached['timestamp']) < $ttl) {
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
            } catch (\Exception) {
                return false;
            }
        }
    }
}
