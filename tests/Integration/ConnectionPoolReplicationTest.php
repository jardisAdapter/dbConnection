<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration tests for ConnectionPool with actual replica databases.
 *
 * These tests require mysql-replica1 and mysql-replica2 containers to be running.
 * Note: For test purposes, these are independent MySQL instances, not actual replicas.
 */
class ConnectionPoolReplicationTest extends TestCase
{
    private ConnectionFactory $factory;
    private string $database;
    private string $rootPassword;

    protected function setUp(): void
    {
        $this->factory = new ConnectionFactory();
        $this->database = $_ENV['MYSQL_DATABASE'] ?? 'test_db';
        $this->rootPassword = $_ENV['MYSQL_ROOT_PASSWORD'] ?? 'root_password';
    }

    private function createPrimary(): DbConnectionInterface
    {
        return $this->factory->mysql(
            host: $_ENV['MYSQL_HOST'] ?? 'mysql',
            user: 'root',
            password: $this->rootPassword,
            database: $this->database,
            port: (int) ($_ENV['MYSQL_PORT'] ?? 3306)
        );
    }

    private function createReplica1(): DbConnectionInterface
    {
        return $this->factory->mysql(
            host: $_ENV['MYSQL_REPLICA1_HOST'] ?? 'mysql-replica1',
            user: 'root',
            password: $this->rootPassword,
            database: $this->database,
            port: (int) ($_ENV['MYSQL_PORT'] ?? 3306)
        );
    }

    private function createReplica2(): DbConnectionInterface
    {
        return $this->factory->mysql(
            host: $_ENV['MYSQL_REPLICA2_HOST'] ?? 'mysql-replica2',
            user: 'root',
            password: $this->rootPassword,
            database: $this->database,
            port: (int) ($_ENV['MYSQL_PORT'] ?? 3306)
        );
    }

    public function testPoolWithMultipleReplicas(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createPrimary(),
            readers: [$this->createReplica1(), $this->createReplica2()]
        );

        $writer = $pool->getWriter();
        $this->assertInstanceOf(DbConnectionInterface::class, $writer);

        $reader1 = $pool->getReader();
        $reader2 = $pool->getReader();

        $this->assertInstanceOf(DbConnectionInterface::class, $reader1);
        $this->assertInstanceOf(DbConnectionInterface::class, $reader2);
    }

    public function testWriteAndReadOperationsAcrossServers(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createPrimary(),
            readers: [$this->createReplica1(), $this->createReplica2()]
        );

        $writer = $pool->getWriter();
        $pdo = $writer->pdo();
        $pdo->exec('DROP TABLE IF EXISTS test_replication');
        $pdo->exec('CREATE TABLE test_replication (id INT PRIMARY KEY AUTO_INCREMENT, value VARCHAR(100), server VARCHAR(50))');

        $stmt = $pdo->prepare('INSERT INTO test_replication (value, server) VALUES (?, @@hostname)');
        $stmt->execute(['primary_data']);

        $reader1 = $pool->getReader();
        $reader2 = $pool->getReader();

        $this->assertInstanceOf(DbConnectionInterface::class, $reader1);
        $this->assertInstanceOf(DbConnectionInterface::class, $reader2);

        $pdo->exec('DROP TABLE test_replication');
    }

    public function testLoadBalancingAcrossReplicas(): void
    {
        $replica1 = $this->createReplica1();
        $replica2 = $this->createReplica2();

        $pool = new ConnectionPool(
            writer: $this->createPrimary(),
            readers: [$replica1, $replica2],
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
            writer: $this->createPrimary(),
            readers: [$this->createReplica1(), $this->createReplica2()],
            config: new ConnectionPoolConfig(
                validateConnections: true,
                healthCheckCacheTtl: 5
            )
        );

        for ($i = 0; $i < 5; $i++) {
            $reader = $pool->getReader();
            $this->assertInstanceOf(DbConnectionInterface::class, $reader);

            $stmt = $reader->pdo()->query('SELECT 1 as test');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertEquals(1, $result['test']);
        }
    }

    public function testStatsWithMultipleReplicas(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createPrimary(),
            readers: [$this->createReplica1(), $this->createReplica2()]
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

    public function testRandomLoadBalancing(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createPrimary(),
            readers: [$this->createReplica1(), $this->createReplica2()],
            config: new ConnectionPoolConfig(
                loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_RANDOM,
                validateConnections: false
            )
        );

        for ($i = 0; $i < 10; $i++) {
            $this->assertInstanceOf(DbConnectionInterface::class, $pool->getReader());
        }
    }

}
