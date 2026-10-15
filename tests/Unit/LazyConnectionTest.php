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
        $this->expectExceptionMessage('Writer connection health check failed: ');

        $pool->getWriter();
    }

    public function testHealthCheckFailureCarriesConnectionCause(): void
    {
        $writer = (new ConnectionFactory())->mysql('127.0.0.1', 'u', 'p', 'db', 1);
        $pool = new ConnectionPool($writer, config: new ConnectionPoolConfig(validateConnections: true));

        try {
            $pool->getWriter();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $cause = $e->getPrevious();
            $this->assertInstanceOf(RuntimeException::class, $cause);
            $this->assertStringContainsString($cause->getMessage(), $e->getMessage());
            $this->assertInstanceOf(\PDOException::class, $cause->getPrevious());
            $this->assertStringContainsString($cause->getPrevious()->getMessage(), $e->getMessage());
        }
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

    // Bescheid Rolf 2026-10-15: alle Dienste lazy - SQLite oeffnet erst beim ersten pdo().

    public function testSqliteFactoryDoesNotOpenOrCreateFile(): void
    {
        $dir = sys_get_temp_dir() . '/lazy_sqlite_' . uniqid();
        mkdir($dir);
        $path = $dir . '/app.db';

        try {
            $connection = (new ConnectionFactory())->sqlite($path);

            $this->assertFalse($connection->isConnected());
            $this->assertSame([], glob($dir . '/*') ?: []);
            $this->assertFileDoesNotExist($path);
            $this->assertFileDoesNotExist($path . '-wal');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testSqliteFirstPdoOpensFileAndAppliesPragmas(): void
    {
        $dir = sys_get_temp_dir() . '/lazy_sqlite_' . uniqid();
        mkdir($dir);
        $path = $dir . '/app.db';

        try {
            $connection = (new ConnectionFactory())->sqlite($path);
            $pdo = $connection->pdo();

            $this->assertTrue($connection->isConnected());
            $this->assertFileExists($path);
            $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
            $this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('PRAGMA synchronous')->fetchColumn());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testSqlitePragmasAreAppliedAgainAfterReconnect(): void
    {
        $dir = sys_get_temp_dir() . '/lazy_sqlite_' . uniqid();
        mkdir($dir);

        try {
            $connection = (new ConnectionFactory())->sqlite($dir . '/app.db');
            $connection->pdo();
            $connection->reconnect();
            $pdo = $connection->pdo();

            $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
            $this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testSqliteImpossiblePathThrowsOnlyOnFirstAccess(): void
    {
        $connection = (new ConnectionFactory())->sqlite('/non/existent/dir/app.db');

        $this->assertFalse($connection->isConnected());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite database directory is not writable');

        $connection->pdo();
    }

    public function testSqliteMemoryReconnectKeepsTheExistingDatabase(): void
    {
        $connection = (new ConnectionFactory())->sqlite();
        $connection->pdo()->exec('CREATE TABLE t (id INTEGER)');
        $connection->pdo()->exec('INSERT INTO t VALUES (1)');

        $connection->reconnect();

        $this->assertSame(1, (int) $connection->pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testSqliteMemoryPoolHealthCheckKeepsTheDatabase(): void
    {
        $writer = (new ConnectionFactory())->sqlite();
        $pool = new ConnectionPool($writer, config: new ConnectionPoolConfig(
            validateConnections: true,
            healthCheckCacheTtl: 0,
        ));

        $this->assertFalse($writer->isConnected());
        $pool->getWriter()->pdo()->exec('CREATE TABLE t (id INTEGER)');
        $pool->getWriter()->pdo()->exec('INSERT INTO t VALUES (1)');

        $this->assertSame(1, (int) $pool->getWriter()->pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testSqliteMemoryReconnectAfterExplicitDisconnectOpensFreshDatabase(): void
    {
        $connection = (new ConnectionFactory())->sqlite();
        $connection->pdo()->exec('CREATE TABLE t (id INTEGER)');
        $connection->disconnect();

        $connection->reconnect();

        $this->assertTrue($connection->isConnected());
        $this->assertSame(1, (int) $connection->pdo()->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE name = 'nothing'"
        )->fetchColumn() + 1);
        $this->assertSame(0, (int) $connection->pdo()->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE name = 't'"
        )->fetchColumn());
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
