<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use PDO;

class ConnectionPoolTest extends TestCase
{
    private ConnectionFactory $factory;
    private string $host;
    private string $user;
    private string $password;
    private string $database;
    private int $port;

    protected function setUp(): void
    {
        $this->factory = new ConnectionFactory();
        $this->host = $_ENV['MYSQL_HOST'] ?? 'mysql';
        $this->user = $_ENV['MYSQL_USER'] ?? 'test_user';
        $this->password = $_ENV['MYSQL_PASSWORD'] ?? 'test_password';
        $this->database = $_ENV['MYSQL_DATABASE'] ?? 'test_db';
        $this->port = (int) ($_ENV['MYSQL_PORT'] ?? 3306);
    }

    private function createWriter(): DbConnectionInterface
    {
        return $this->factory->mysql(
            $this->host,
            $this->user,
            $this->password,
            $this->database,
            $this->port
        );
    }

    private function createReader(): DbConnectionInterface
    {
        return $this->factory->mysql(
            $this->host,
            $this->user,
            $this->password,
            $this->database,
            $this->port
        );
    }

    public function testPoolCreation(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader(), $this->createReader()]
        );

        $this->assertInstanceOf(ConnectionPool::class, $pool);
    }

    public function testEmptyReadersUsesWriterAsReader(): void
    {
        $pool = new ConnectionPool(writer: $this->createWriter());

        $writer = $pool->getWriter();
        $reader = $pool->getReader();

        $this->assertSame($writer, $reader);

        $stats = $pool->getStats();
        $this->assertEquals(1, $stats['readers']);
    }

    public function testGetWriterReturnsConnection(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader()]
        );

        $writer = $pool->getWriter();
        $this->assertInstanceOf(DbConnectionInterface::class, $writer);
        $this->assertInstanceOf(PDO::class, $writer->pdo());
    }

    public function testGetReaderReturnsConnection(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader()]
        );

        $reader = $pool->getReader();
        $this->assertInstanceOf(DbConnectionInterface::class, $reader);
        $this->assertInstanceOf(PDO::class, $reader->pdo());
    }

    public function testRoundRobinLoadBalancing(): void
    {
        $reader1 = $this->createReader();
        $reader2 = $this->createReader();

        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$reader1, $reader2],
            config: new ConnectionPoolConfig(
                loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_ROUND_ROBIN,
                validateConnections: false
            )
        );

        $first = $pool->getReader();
        $second = $pool->getReader();
        $third = $pool->getReader();

        $this->assertNotSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function testRandomLoadBalancing(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader(), $this->createReader()],
            config: new ConnectionPoolConfig(
                loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_RANDOM,
                validateConnections: false
            )
        );

        for ($i = 0; $i < 10; $i++) {
            $reader = $pool->getReader();
            $this->assertInstanceOf(DbConnectionInterface::class, $reader);
        }
    }

    public function testWeightedLoadBalancingStrategyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid load balancing strategy');

        new ConnectionPoolConfig(loadBalancingStrategy: 'weighted');
    }

    public function testStatsTracking(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader()]
        );

        $initialStats = $pool->getStats();
        $this->assertEquals(0, $initialStats['reads']);
        $this->assertEquals(0, $initialStats['writes']);
        $this->assertEquals(0, $initialStats['failovers']);
        $this->assertEquals(1, $initialStats['readers']);

        $pool->getWriter();
        $pool->getReader();
        $pool->getReader();

        $stats = $pool->getStats();
        $this->assertEquals(2, $stats['reads']);
        $this->assertEquals(1, $stats['writes']);
    }

    public function testResetStats(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader()]
        );

        $pool->getWriter();
        $pool->getReader();
        $pool->resetStats();

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['reads']);
        $this->assertEquals(0, $stats['writes']);
        $this->assertEquals(0, $stats['failovers']);
    }

    public function testHealthCheckWithValidConnection(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader()],
            config: new ConnectionPoolConfig(validateConnections: true)
        );

        $reader = $pool->getReader();
        $this->assertInstanceOf(DbConnectionInterface::class, $reader);
    }

    public function testWriterHealthCheckFails(): void
    {
        $writer = $this->factory->fromPdo(
            new \PDO('sqlite::memory:'),
            manageLifecycle: true
        );
        $writer->disconnect();

        $pool = new ConnectionPool(
            writer: $writer,
            config: new ConnectionPoolConfig(validateConnections: true)
        );

        $this->expectException(RuntimeException::class);
        $pool->getWriter();
    }

    public function testFailedWriterDoesNotIncrementStats(): void
    {
        $writer = $this->factory->fromPdo(
            new \PDO('sqlite::memory:'),
            manageLifecycle: true
        );
        $writer->disconnect();

        $pool = new ConnectionPool(
            writer: $writer,
            config: new ConnectionPoolConfig(validateConnections: true)
        );

        try {
            $pool->getWriter();
        } catch (RuntimeException) {
            // expected
        }

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['writes']);
    }

    public function testFailedAllReadersDoesNotIncrementReadStats(): void
    {
        $reader1 = $this->factory->fromPdo(new \PDO('sqlite::memory:'), manageLifecycle: true);
        $reader2 = $this->factory->fromPdo(new \PDO('sqlite::memory:'), manageLifecycle: true);
        $reader1->disconnect();
        $reader2->disconnect();

        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$reader1, $reader2],
            config: new ConnectionPoolConfig(validateConnections: true)
        );

        try {
            $pool->getReader();
        } catch (RuntimeException) {
            // expected
        }

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['reads']);
        $this->assertEquals(2, $stats['failovers']);
    }

    public function testFailoverTriesEachReaderExactlyOnce(): void
    {
        $healthy = $this->factory->sqlite();
        $dead = $this->factory->fromPdo(new \PDO('sqlite::memory:'), manageLifecycle: true);
        $dead->disconnect();

        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$dead, $healthy],
            config: new ConnectionPoolConfig(validateConnections: true)
        );

        $reader = $pool->getReader();
        $this->assertSame($healthy, $reader);

        $stats = $pool->getStats();
        $this->assertEquals(1, $stats['reads']);
        $this->assertEquals(1, $stats['failovers']);
    }

    public function testNegativeHealthCacheNotCachedByDefault(): void
    {
        $sqlite = $this->factory->sqlite();

        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$sqlite],
            config: new ConnectionPoolConfig(
                validateConnections: true,
                healthCheckCacheTtl: 60,
                healthCheckNegativeCacheTtl: 0,
            )
        );

        // First call succeeds
        $reader = $pool->getReader();
        $this->assertSame($sqlite, $reader);

        // Connection is healthy on next call too (positive cache)
        $reader2 = $pool->getReader();
        $this->assertSame($sqlite, $reader2);
    }

    public function testHealthCheckCaching(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader()],
            config: new ConnectionPoolConfig(
                validateConnections: true,
                healthCheckCacheTtl: 60
            )
        );

        $pool->getReader();

        $start = microtime(true);
        $pool->getReader();
        $duration = microtime(true) - $start;

        $this->assertLessThan(0.01, $duration);
    }

    public function testActualDatabaseOperations(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader()]
        );

        $writer = $pool->getWriter();
        $pdo = $writer->pdo();
        $pdo->exec('DROP TABLE IF EXISTS test_pool');
        $pdo->exec('CREATE TABLE test_pool (id INT PRIMARY KEY, value VARCHAR(100))');

        $stmt = $pdo->prepare('INSERT INTO test_pool (id, value) VALUES (?, ?)');
        $stmt->execute([1, 'test_value']);

        $reader = $pool->getReader();
        $stmt = $reader->pdo()->query('SELECT value FROM test_pool WHERE id = 1');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('test_value', $result['value']);

        $pdo->exec('DROP TABLE test_pool');
    }

    public function testPoolWithSqliteDriver(): void
    {
        $writer = $this->factory->sqlite();

        $pool = new ConnectionPool(writer: $writer);

        $this->assertSame($writer, $pool->getWriter());
        $this->assertSame($writer, $pool->getReader());
    }

    public function testPoolWithPostgresDriver(): void
    {
        $writer = $this->factory->postgres(
            host: $_ENV['POSTGRES_HOST'] ?? 'postgres',
            user: $_ENV['POSTGRES_USER'] ?? 'test_user',
            password: $_ENV['POSTGRES_PASSWORD'] ?? 'test_password',
            database: $_ENV['POSTGRES_DATABASE'] ?? 'test_db',
            port: (int) ($_ENV['POSTGRES_PORT'] ?? 5432)
        );

        $pool = new ConnectionPool(writer: $writer);

        $this->assertInstanceOf(DbConnectionInterface::class, $pool->getWriter());
    }

    public function testPoolWithExternalDriver(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s',
            $this->host,
            $this->port,
            $this->database
        );
        $pdo = new PDO($dsn, $this->user, $this->password);

        $writer = $this->factory->fromPdo($pdo);

        $pool = new ConnectionPool(writer: $writer);

        $this->assertSame($pdo, $pool->getWriter()->pdo());
    }

    public function testGetReadersReturnsAllReaderConnections(): void
    {
        $reader1 = $this->createReader();
        $reader2 = $this->createReader();

        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$reader1, $reader2],
            config: new ConnectionPoolConfig(validateConnections: false)
        );

        $readers = $pool->getReaders();

        $this->assertCount(2, $readers);
        $this->assertSame($reader1, $readers[0]);
        $this->assertSame($reader2, $readers[1]);
    }

    public function testGetReadersWithEmptyReadersReturnsWriter(): void
    {
        $pool = new ConnectionPool(writer: $this->createWriter());

        $readers = $pool->getReaders();

        $this->assertCount(1, $readers);
        $this->assertSame($pool->getWriter(), $readers[0]);
    }

    public function testGetReaderCountWithMultipleReaders(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader(), $this->createReader()]
        );

        $this->assertEquals(2, $pool->getReaderCount());
    }

    public function testGetReaderCountWithEmptyReaders(): void
    {
        $pool = new ConnectionPool(writer: $this->createWriter());

        $this->assertEquals(1, $pool->getReaderCount());
    }

    public function testGetReaderCountDoesNotCreateConnections(): void
    {
        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$this->createReader(), $this->createReader()]
        );

        $this->assertEquals(2, $pool->getReaderCount());

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['reads']);
        $this->assertEquals(0, $stats['writes']);
    }

    public function testGetReadersAndGetReaderShareConnections(): void
    {
        $reader1 = $this->createReader();
        $reader2 = $this->createReader();

        $pool = new ConnectionPool(
            writer: $this->createWriter(),
            readers: [$reader1, $reader2],
            config: new ConnectionPoolConfig(validateConnections: false)
        );

        $allReaders = $pool->getReaders();
        $reader = $pool->getReader();

        $this->assertTrue(
            $reader === $allReaders[0] || $reader === $allReaders[1],
            'getReader() should return an instance from getReaders()'
        );
    }
}
