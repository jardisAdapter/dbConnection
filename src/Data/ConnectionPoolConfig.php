<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Data;

use InvalidArgumentException;

/**
 * Configuration for ConnectionPool
 *
 * This class provides configuration options for the ConnectionPool, which manages
 * read/write splitting and load balancing across database servers.
 */
final readonly class ConnectionPoolConfig
{
    public const STRATEGY_ROUND_ROBIN = 'round-robin';
    public const STRATEGY_RANDOM = 'random';
    public const STRATEGY_WEIGHTED = 'weighted';

    private const VALID_STRATEGIES = [
        self::STRATEGY_ROUND_ROBIN,
        self::STRATEGY_RANDOM,
        self::STRATEGY_WEIGHTED,
    ];

    /**
     * @param bool $usePersistent Use PDO persistent connections (recommended for PHP-FPM)
     * @param bool $validateConnections Perform health checks before returning connections
     * @param int $healthCheckCacheTtl TTL in seconds for caching health check results
     * @param string $loadBalancingStrategy Strategy for distributing read queries (use STRATEGY_* constants)
     * @param int $maxRetries Maximum number of retry attempts when a reader fails
     * @param int $connectionTimeout Connection timeout in seconds
     * @throws InvalidArgumentException If any parameter value is invalid
     */
    public function __construct(
        public bool $usePersistent = true,
        public bool $validateConnections = true,
        public int $healthCheckCacheTtl = 30,
        public string $loadBalancingStrategy = self::STRATEGY_ROUND_ROBIN,
        public int $maxRetries = 3,
        public int $connectionTimeout = 5
    ) {
        if ($healthCheckCacheTtl < 0) {
            throw new InvalidArgumentException('Health check cache TTL must be non-negative');
        }

        if (!in_array($loadBalancingStrategy, self::VALID_STRATEGIES, true)) {
            throw new InvalidArgumentException(
                "Invalid load balancing strategy: {$loadBalancingStrategy}. " .
                'Allowed values: ' . implode(', ', self::VALID_STRATEGIES)
            );
        }

        if ($maxRetries < 0) {
            throw new InvalidArgumentException('Max retries must be non-negative');
        }

        if ($connectionTimeout <= 0) {
            throw new InvalidArgumentException('Connection timeout must be positive');
        }
    }
}
