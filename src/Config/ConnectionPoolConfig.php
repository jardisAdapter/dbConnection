<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Config;

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

    private const VALID_STRATEGIES = [
        self::STRATEGY_ROUND_ROBIN,
        self::STRATEGY_RANDOM,
    ];

    /**
     * @param bool $validateConnections Perform health checks before returning connections
     * @param int $healthCheckCacheTtl TTL in seconds for caching positive health check results
     * @param int $healthCheckNegativeCacheTtl TTL in seconds for caching negative health check results (0 = no caching)
     * @param string $loadBalancingStrategy Strategy for distributing read queries (use STRATEGY_* constants)
     * @param bool $stickyWriterDuringTransaction While the writer has an open transaction,
     *        getReader() returns the writer instead of an independent reader, so reads inside
     *        the transaction see the same uncommitted state the transaction is writing.
     * @throws InvalidArgumentException If any parameter value is invalid
     */
    public function __construct(
        public bool $validateConnections = true,
        public int $healthCheckCacheTtl = 30,
        public int $healthCheckNegativeCacheTtl = 0,
        public string $loadBalancingStrategy = self::STRATEGY_ROUND_ROBIN,
        public bool $stickyWriterDuringTransaction = false,
    ) {
        if ($healthCheckCacheTtl < 0) {
            throw new InvalidArgumentException('Health check cache TTL must be non-negative');
        }

        if ($healthCheckNegativeCacheTtl < 0) {
            throw new InvalidArgumentException('Health check negative cache TTL must be non-negative');
        }

        if (!in_array($loadBalancingStrategy, self::VALID_STRATEGIES, true)) {
            throw new InvalidArgumentException(
                "Invalid load balancing strategy: {$loadBalancingStrategy}. " .
                'Allowed values: ' . implode(', ', self::VALID_STRATEGIES)
            );
        }
    }
}
