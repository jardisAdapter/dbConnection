<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit;

use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Config\SqliteConfig;
use JardisAdapter\DbConnection\Connection\PdoConnection;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The factory builds mysql/postgres connections without opening a socket;
 * the connection opens on first access. 127.0.0.1:1 is a closed port, so any
 * real connect attempt fails fast and deterministically without a server.
 */
final class LazyConnectionTest extends TestCase
{
    public function testMysqlFactoryDoesNotConnect(): void
    {
        $connection = (new ConnectionFactory())->mysql('127.0.0.1', 'u', 'p', 'db', 1);

        $this->assertFalse($connection->isConnected());
        $this->assertSame('mysql', $connection->getDriverName());
    }

    public function testPostgresFactoryDoesNotConnect(): void
    {
        $connection = (new ConnectionFactory())->postgres('127.0.0.1', 'u', 'p', 'db', 1);

        $this->assertFalse($connection->isConnected());
    }

    public function testFirstAccessThrowsTheConnectionError(): void
    {
        $connection = (new ConnectionFactory())->mysql('127.0.0.1', 'u', 'p', 'db', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to mysql database');

        $connection->pdo();
    }

    public function testBeginTransactionConnectsLazilyAndThrowsOnUnreachableServer(): void
    {
        $connection = (new ConnectionFactory())->mysql('127.0.0.1', 'u', 'p', 'db', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to mysql database');

        $connection->beginTransaction();
    }

    public function testPoolBuildsWithoutConnectingAndFailsOnFirstAccess(): void
    {
        $writer = (new ConnectionFactory())->mysql('127.0.0.1', 'u', 'p', 'db', 1);
        $pool = new ConnectionPool($writer);

        $this->assertFalse($writer->isConnected());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Writer connection health check failed');

        $pool->getWriter();
    }

    public function testPoolWithoutHealthCheckDoesNotConnectOnGetWriter(): void
    {
        $writer = (new ConnectionFactory())->mysql('127.0.0.1', 'u', 'p', 'db', 1);
        $pool = new ConnectionPool($writer, config: new ConnectionPoolConfig(validateConnections: false));

        $this->assertSame($writer, $pool->getWriter());
        $this->assertFalse($writer->isConnected());
    }

    public function testExplicitDisconnectIsNotReopenedImplicitly(): void
    {
        $connection = (new ConnectionFactory())->fromPdo(new \PDO('sqlite::memory:'), manageLifecycle: true);
        $connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $connection->pdo();
    }

    public function testIsConnectedBecomesTrueAfterFirstAccess(): void
    {
        $connection = new PdoConnection(new SqliteConfig(path: ':memory:'));

        $this->assertFalse($connection->isConnected());

        $connection->pdo();

        $this->assertTrue($connection->isConnected());
    }
}
