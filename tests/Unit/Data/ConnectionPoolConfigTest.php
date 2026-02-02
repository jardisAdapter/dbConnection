<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Data;

use JardisAdapter\DbConnection\Data\ConnectionPoolConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ConnectionPoolConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new ConnectionPoolConfig();

        $this->assertTrue($config->usePersistent);
        $this->assertTrue($config->validateConnections);
        $this->assertEquals(30, $config->healthCheckCacheTtl);
        $this->assertEquals(ConnectionPoolConfig::STRATEGY_ROUND_ROBIN, $config->loadBalancingStrategy);
        $this->assertEquals(3, $config->maxRetries);
        $this->assertEquals(5, $config->connectionTimeout);
    }

    public function testCustomValues(): void
    {
        $config = new ConnectionPoolConfig(
            usePersistent: false,
            validateConnections: false,
            healthCheckCacheTtl: 60,
            loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_RANDOM,
            maxRetries: 5,
            connectionTimeout: 10
        );

        $this->assertFalse($config->usePersistent);
        $this->assertFalse($config->validateConnections);
        $this->assertEquals(60, $config->healthCheckCacheTtl);
        $this->assertEquals(ConnectionPoolConfig::STRATEGY_RANDOM, $config->loadBalancingStrategy);
        $this->assertEquals(5, $config->maxRetries);
        $this->assertEquals(10, $config->connectionTimeout);
    }

    public function testNegativeHealthCheckTtlThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Health check cache TTL must be non-negative');

        new ConnectionPoolConfig(healthCheckCacheTtl: -1);
    }

    public function testInvalidLoadBalancingStrategyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid load balancing strategy');

        new ConnectionPoolConfig(loadBalancingStrategy: 'invalid');
    }

    public function testValidLoadBalancingStrategies(): void
    {
        $strategies = [ConnectionPoolConfig::STRATEGY_ROUND_ROBIN, ConnectionPoolConfig::STRATEGY_RANDOM, ConnectionPoolConfig::STRATEGY_WEIGHTED];

        foreach ($strategies as $strategy) {
            $config = new ConnectionPoolConfig(loadBalancingStrategy: $strategy);
            $this->assertEquals($strategy, $config->loadBalancingStrategy);
        }
    }

    public function testNegativeMaxRetriesThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max retries must be non-negative');

        new ConnectionPoolConfig(maxRetries: -1);
    }

    public function testZeroMaxRetriesIsAllowed(): void
    {
        $config = new ConnectionPoolConfig(maxRetries: 0);
        $this->assertEquals(0, $config->maxRetries);
    }

    public function testNegativeConnectionTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Connection timeout must be positive');

        new ConnectionPoolConfig(connectionTimeout: 0);
    }

    public function testZeroHealthCheckTtlIsAllowed(): void
    {
        $config = new ConnectionPoolConfig(healthCheckCacheTtl: 0);
        $this->assertEquals(0, $config->healthCheckCacheTtl);
    }

    public function testPropertiesAreReadonly(): void
    {
        $config = new ConnectionPoolConfig();

        $reflection = new \ReflectionClass($config);

        $properties = [
            'usePersistent',
            'validateConnections',
            'healthCheckCacheTtl',
            'loadBalancingStrategy',
            'maxRetries',
            'connectionTimeout'
        ];

        foreach ($properties as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$propertyName} should be readonly"
            );
        }
    }
}
