<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\MySql;
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Data\MySqlConfig;
use JardisAdapter\DbConnection\Data\ConnectionPoolConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use PDO;

/**
 * Integration tests for ConnectionPool with actual replica databases
 *
 * These tests require mysql-replica1 and mysql-replica2 containers to be running.
 * Note: For test purposes, these are independent MySQL instances, not actual replicas.
 */
class ConnectionPoolReplicationTest extends TestCase
{
    private MySqlConfig $primaryConfig;
    private MySqlConfig $replica1Config;
    private MySqlConfig $replica2Config;

    protected function setUp(): void
    {
        $this->primaryConfig = new MySqlConfig(
            host: $_ENV['MYSQL_HOST'] ?? 'mysql',
            user: 'root',
            password: $_ENV['MYSQL_ROOT_PASSWORD'] ?? 'root_password',
            database: $_ENV['MYSQL_DATABASE'] ?? 'test_db',
            port: (int)($_ENV['MYSQL_PORT'] ?? 3306)
        );

        $this->replica1Config = new MySqlConfig(
            host: $_ENV['MYSQL_REPLICA1_HOST'] ?? 'mysql-replica1',
            user: 'root',
            password: $_ENV['MYSQL_ROOT_PASSWORD'] ?? 'root_password',
            database: $_ENV['MYSQL_DATABASE'] ?? 'test_db',
            port: (int)($_ENV['MYSQL_PORT'] ?? 3306)
        );

        $this->replica2Config = new MySqlConfig(
            host: $_ENV['MYSQL_REPLICA2_HOST'] ?? 'mysql-replica2',
            user: 'root',
            password: $_ENV['MYSQL_ROOT_PASSWORD'] ?? 'root_password',
            database: $_ENV['MYSQL_DATABASE'] ?? 'test_db',
            port: (int)($_ENV['MYSQL_PORT'] ?? 3306)
        );
    }

    public function testPoolWithMultipleReplicas(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config, $this->replica2Config],
            driverClass: MySql::class
        );

        $writer = $pool->getWriter();
        $this->assertInstanceOf(MySql::class, $writer);

        $reader1 = $pool->getReader();
        $reader2 = $pool->getReader();

        $this->assertInstanceOf(MySql::class, $reader1);
        $this->assertInstanceOf(MySql::class, $reader2);
    }

    public function testWriteAndReadOperationsAcrossServers(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config, $this->replica2Config],
            driverClass: MySql::class
        );

        $writer = $pool->getWriter();
        $pdo = $writer->pdo();
        $pdo->exec('DROP TABLE IF EXISTS test_replication');
        $pdo->exec('CREATE TABLE test_replication (id INT PRIMARY KEY AUTO_INCREMENT, value VARCHAR(100), server VARCHAR(50))');

        $stmt = $pdo->prepare('INSERT INTO test_replication (value, server) VALUES (?, @@hostname)');
        $stmt->execute(['primary_data']);

        $writtenId = (int)$pdo->lastInsertId();


        $reader1 = $pool->getReader();
        $reader2 = $pool->getReader();

        $this->assertInstanceOf(MySql::class, $reader1);
        $this->assertInstanceOf(MySql::class, $reader2);

        $pdo->exec('DROP TABLE test_replication');
    }

    public function testLoadBalancingAcrossReplicas(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config, $this->replica2Config],
            driverClass: MySql::class,
            config: new ConnectionPoolConfig(
                loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_ROUND_ROBIN,
                validateConnections: false
            )
        );

        $connections = [];
        for ($i = 0; $i < 4; $i++) {
            $connections[] = $pool->getReader();
        }

        $this->assertNotSame($connections[0], $connections[1]);
        $this->assertSame($connections[0], $connections[2]);
        $this->assertSame($connections[1], $connections[3]);
    }

    public function testHealthCheckAcrossMultipleReplicas(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config, $this->replica2Config],
            driverClass: MySql::class,
            config: new ConnectionPoolConfig(
                validateConnections: true,
                healthCheckCacheTtl: 5
            )
        );

        for ($i = 0; $i < 5; $i++) {
            $reader = $pool->getReader();
            $this->assertInstanceOf(MySql::class, $reader);

            $pdo = $reader->pdo();
            $stmt = $pdo->query('SELECT 1 as test');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertEquals(1, $result['test']);
        }
    }

    public function testFailoverWithOneReplicaDown(): void
    {
        $invalidConfig = new MySqlConfig(
            host: 'nonexistent-replica',
            user: 'test',
            password: 'test',
            database: 'test'
        );

        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$invalidConfig, $this->replica1Config, $this->replica2Config],
            driverClass: MySql::class,
            config: new ConnectionPoolConfig(
                validateConnections: true,
                maxRetries: 3,
                connectionTimeout: 1
            )
        );

        $this->expectException(RuntimeException::class);
        $pool->getReader(); // Exception now thrown on first use
    }

    public function testPersistentConnectionsAcrossReplicas(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config, $this->replica2Config],
            driverClass: MySql::class,
            config: new ConnectionPoolConfig(usePersistent: true)
        );

        $reader1 = $pool->getReader();
        $pdo1 = $reader1->pdo();
        $isPersistent1 = $pdo1->getAttribute(PDO::ATTR_PERSISTENT);

        $reader2 = $pool->getReader();
        $pdo2 = $reader2->pdo();
        $isPersistent2 = $pdo2->getAttribute(PDO::ATTR_PERSISTENT);

        $this->assertTrue((bool)$isPersistent1);
        $this->assertTrue((bool)$isPersistent2);
    }

    public function testStatsWithMultipleReplicas(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config, $this->replica2Config],
            driverClass: MySql::class
        );

        $initialStats = $pool->getStats();
        $this->assertEquals(2, $initialStats['readers']);

        $pool->getWriter();
        $pool->getReader();
        $pool->getReader();
        $pool->getReader();

        $stats = $pool->getStats();
        $this->assertEquals(1, $stats['writes']);
        $this->assertEquals(3, $stats['reads']);
        $this->assertEquals(2, $stats['readers']);
    }

    public function testConnectionTimeoutConfiguration(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config],
            driverClass: MySql::class,
            config: new ConnectionPoolConfig(connectionTimeout: 10)
        );

        $reader = $pool->getReader();
        $pdo = $reader->pdo();

        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testRandomLoadBalancing(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config, $this->replica2Config],
            driverClass: MySql::class,
            config: new ConnectionPoolConfig(
                loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_RANDOM,
                validateConnections: false
            )
        );

        $readers = [];
        for ($i = 0; $i < 10; $i++) {
            $readers[] = $pool->getReader();
        }

        foreach ($readers as $reader) {
            $this->assertInstanceOf(MySql::class, $reader);
        }
    }

    public function testWeightedLoadBalancing(): void
    {
        $pool = new ConnectionPool(
            writer: $this->primaryConfig,
            readers: [$this->replica1Config, $this->replica2Config],
            driverClass: MySql::class,
            config: new ConnectionPoolConfig(
                loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_WEIGHTED,
                validateConnections: false
            )
        );

        $reader1 = $pool->getReader();
        $reader2 = $pool->getReader();
        $reader3 = $pool->getReader();

        $this->assertNotSame($reader1, $reader2);
        $this->assertSame($reader1, $reader3);
    }
}
