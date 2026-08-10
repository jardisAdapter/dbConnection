<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Config;

use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ConnectionPoolConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new ConnectionPoolConfig();

        $this->assertTrue($config->validateConnections);
        $this->assertEquals(30, $config->healthCheckCacheTtl);
        $this->assertEquals(0, $config->healthCheckNegativeCacheTtl);
        $this->assertEquals(ConnectionPoolConfig::STRATEGY_ROUND_ROBIN, $config->loadBalancingStrategy);
        $this->assertFalse($config->stickyWriterDuringTransaction);
    }

    /**
     * U1: stickyWriterDuringTransaction defaults to false — bestand darf ohne Opt-in
     * keine Verhaltensaenderung erfahren.
     */
    public function testStickyWriterDuringTransactionDefaultsToFalse(): void
    {
        $config = new ConnectionPoolConfig();

        $this->assertFalse($config->stickyWriterDuringTransaction);
    }

    public function testCustomValues(): void
    {
        $config = new ConnectionPoolConfig(
            validateConnections: false,
            healthCheckCacheTtl: 60,
            healthCheckNegativeCacheTtl: 10,
            loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_RANDOM,
            stickyWriterDuringTransaction: true,
        );

        $this->assertFalse($config->validateConnections);
        $this->assertEquals(60, $config->healthCheckCacheTtl);
        $this->assertEquals(10, $config->healthCheckNegativeCacheTtl);
        $this->assertEquals(ConnectionPoolConfig::STRATEGY_RANDOM, $config->loadBalancingStrategy);
        $this->assertTrue($config->stickyWriterDuringTransaction);
    }

    public function testNegativeHealthCheckTtlThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Health check cache TTL must be non-negative');

        new ConnectionPoolConfig(healthCheckCacheTtl: -1);
    }

    public function testNegativeNegativeCacheTtlThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Health check negative cache TTL must be non-negative');

        new ConnectionPoolConfig(healthCheckNegativeCacheTtl: -1);
    }

    public function testInvalidLoadBalancingStrategyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid load balancing strategy');

        new ConnectionPoolConfig(loadBalancingStrategy: 'invalid');
    }

    public function testValidLoadBalancingStrategies(): void
    {
        $strategies = [ConnectionPoolConfig::STRATEGY_ROUND_ROBIN, ConnectionPoolConfig::STRATEGY_RANDOM];

        foreach ($strategies as $strategy) {
            $config = new ConnectionPoolConfig(loadBalancingStrategy: $strategy);
            $this->assertEquals($strategy, $config->loadBalancingStrategy);
        }
    }

    public function testWeightedStrategyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid load balancing strategy');

        new ConnectionPoolConfig(loadBalancingStrategy: 'weighted');
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
            'validateConnections',
            'healthCheckCacheTtl',
            'healthCheckNegativeCacheTtl',
            'loadBalancingStrategy',
            'stickyWriterDuringTransaction',
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
