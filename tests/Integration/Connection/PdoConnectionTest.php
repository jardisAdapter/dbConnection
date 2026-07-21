<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration\Connection;

use JardisAdapter\DbConnection\Connection\PdoConnection;
use JardisAdapter\DbConnection\Config\SqliteConfig;
use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for PdoConnection base class.
 * Uses SQLite for testing as it requires no external dependencies.
 */
final class PdoConnectionTest extends TestCase
{
    private ?PdoConnection $connection = null;
    private string $testDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDbPath = sys_get_temp_dir() . '/test_pdo_connection_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->disconnect();
            $this->connection = null;
        }

        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }

        parent::tearDown();
    }

    public function testPdoReturnsValidPdoInstance(): void
    {
        $this->connection = $this->createConnection();

        $pdo = $this->connection->pdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testPdoThrowsExceptionWhenNotConnected(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->pdo();
    }

    public function testIsConnectedReturnsTrueWhenConnected(): void
    {
        $this->connection = $this->createConnection();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenDisconnected(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->disconnect();

        $this->assertFalse($this->connection->isConnected());
    }

    public function testDisconnectClearsConnection(): void
    {
        $this->connection = $this->createConnection();
        $this->assertTrue($this->connection->isConnected());

        $this->connection->disconnect();

        $this->assertFalse($this->connection->isConnected());
    }

    public function testReconnectRestoresConnection(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->disconnect();

        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testBeginTransactionStartsTransaction(): void
    {
        $this->connection = $this->createConnection();

        $this->connection->beginTransaction();

        $this->assertTrue($this->connection->inTransaction());
    }

    public function testCommitEndsTransaction(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->beginTransaction();

        $this->connection->commit();

        $this->assertFalse($this->connection->inTransaction());
    }

    public function testRollbackEndsTransaction(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->beginTransaction();

        $this->connection->rollback();

        $this->assertFalse($this->connection->inTransaction());
    }

    public function testInTransactionReturnsFalseWhenNotInTransaction(): void
    {
        $this->connection = $this->createConnection();

        $this->assertFalse($this->connection->inTransaction());
    }

    public function testGetDatabaseNameReturnsCorrectName(): void
    {
        $this->connection = $this->createConnection();

        $name = $this->connection->getDatabaseName();

        $this->assertSame(basename($this->testDbPath), $name);
    }

    public function testGetDatabaseNameWorksBeforeConnect(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new PdoConnection($config);

        // getDatabaseName() now comes from config, so it works without connecting
        $name = $this->connection->getDatabaseName();

        $this->assertSame(basename($this->testDbPath), $name);
    }

    public function testGetDriverNameReturnsConfigDriverName(): void
    {
        $this->connection = $this->createConnection();

        $this->assertSame('sqlite', $this->connection->getDriverName());
    }

    public function testGetServerVersionReturnsSqliteVersion(): void
    {
        $this->connection = $this->createConnection();

        $version = $this->connection->getServerVersion();

        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function testBeginTransactionThrowsExceptionWhenDisconnected(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->beginTransaction();
    }

    public function testCommitThrowsExceptionWhenDisconnected(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->beginTransaction();
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->commit();
    }

    public function testRollbackThrowsExceptionWhenDisconnected(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->beginTransaction();
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->rollback();
    }

    public function testInTransactionThrowsExceptionWhenDisconnected(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->inTransaction();
    }

    public function testConnectWithInvalidDsnThrowsException(): void
    {
        $config = new SqliteConfig(path: '/invalid/path/database.db');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to sqlite database');

        $connection = new PdoConnection($config);
        $connection->connect();
    }

    public function testReconnectWorksAfterDisconnect(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->disconnect();
        $this->assertFalse($this->connection->isConnected());

        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testCommitWithoutActiveTransaction(): void
    {
        $this->connection = $this->createConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to commit transaction');

        $this->connection->commit();
    }

    public function testRollbackWithoutActiveTransaction(): void
    {
        $this->connection = $this->createConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to rollback transaction');

        $this->connection->rollback();
    }

    public function testNestedTransactionThrowsException(): void
    {
        $this->connection = $this->createConnection();

        $this->connection->beginTransaction();
        $this->assertTrue($this->connection->inTransaction());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to begin transaction');

        $this->connection->beginTransaction();
    }

    private function createConnection(): PdoConnection
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $connection = new PdoConnection($config);
        $connection->connect();
        return $connection;
    }
}
