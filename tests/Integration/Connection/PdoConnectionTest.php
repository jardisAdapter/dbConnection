<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration\Connection;

use JardisAdapter\DbConnection\Connection\PdoConnection;
use JardisAdapter\DbConnection\Data\SqliteConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for PdoConnection base class.
 * Uses SQLite for testing as it requires no external dependencies.
 */
final class PdoConnectionTest extends TestCase
{
    private ?TestPdoConnection $connection = null;
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
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $pdo = $this->connection->pdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testPdoThrowsExceptionWhenNotConnected(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->pdo();
    }

    public function testIsConnectedReturnsTrueWhenConnected(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $this->assertTrue($this->connection->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenDisconnected(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->disconnect();

        $this->assertFalse($this->connection->isConnected());
    }

    public function testDisconnectClearsConnection(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->assertTrue($this->connection->isConnected());

        $this->connection->disconnect();

        $this->assertFalse($this->connection->isConnected());
    }

    public function testReconnectRestoresConnection(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->disconnect();

        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testBeginTransactionStartsTransaction(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $this->connection->beginTransaction();

        $this->assertTrue($this->connection->inTransaction());
    }

    public function testCommitEndsTransaction(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->beginTransaction();

        $this->connection->commit();

        $this->assertFalse($this->connection->inTransaction());
    }

    public function testRollbackEndsTransaction(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->beginTransaction();

        $this->connection->rollback();

        $this->assertFalse($this->connection->inTransaction());
    }

    public function testInTransactionReturnsFalseWhenNotInTransaction(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $this->assertFalse($this->connection->inTransaction());
    }

    public function testGetDatabaseNameReturnsCorrectName(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $name = $this->connection->getDatabaseName();

        $this->assertSame(basename($this->testDbPath), $name);
    }

    public function testGetDatabaseNameThrowsExceptionWhenNotSet(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config, connectImmediately: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database name not available');

        $this->connection->getDatabaseName();
    }

    public function testGetDriverNameReturnsConfigDriverName(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $this->assertSame('sqlite', $this->connection->getDriverName());
    }

    public function testGetServerVersionReturnsSqliteVersion(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $version = $this->connection->getServerVersion();

        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function testBuildDsnThrowsExceptionIfNotImplemented(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $connection = new class($config) extends PdoConnection {
            public function __construct(SqliteConfig $config)
            {
                $this->config = $config;
            }

            public function exposeBuildDsn(): string
            {
                return $this->buildDsn();
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('buildDsn() must be implemented by child class');

        $connection->exposeBuildDsn();
    }

    public function testBeginTransactionThrowsExceptionWhenDisconnected(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->beginTransaction();
    }

    public function testCommitThrowsExceptionWhenDisconnected(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->beginTransaction();
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->commit();
    }

    public function testRollbackThrowsExceptionWhenDisconnected(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->beginTransaction();
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->rollback();
    }

    public function testInTransactionThrowsExceptionWhenDisconnected(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
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

        $this->connection = new TestPdoConnection($config);
    }

    public function testReconnectWorksAfterDisconnect(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);
        $this->connection->disconnect();
        $this->assertFalse($this->connection->isConnected());

        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testGetServerVersionForNonSqlite(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $connection = new class($config) extends PdoConnection {
            public function __construct(SqliteConfig $config)
            {
                $this->config = $config;
                $this->connect('sqlite:' . $config->path, $config, 'test.db');
            }

            protected function buildDsn(): string
            {
                return 'sqlite:' . $this->config->path;
            }
        };

        $version = $connection->getServerVersion();

        $this->assertNotEmpty($version);
    }

    public function testCommitWithoutActiveTransaction(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to commit transaction');

        $this->connection->commit();
    }

    public function testRollbackWithoutActiveTransaction(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to rollback transaction');

        $this->connection->rollback();
    }

    public function testNestedTransactionThrowsException(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new TestPdoConnection($config);

        $this->connection->beginTransaction();
        $this->assertTrue($this->connection->inTransaction());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to begin transaction');

        $this->connection->beginTransaction();
    }

    public function testReconnectWithCustomImplementation(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $connection = new class($config) extends PdoConnection {
            public function __construct(SqliteConfig $config)
            {
                $this->config = $config;
                $this->connect('sqlite:' . $config->path, $config, 'test.db');
            }

            protected function buildDsn(): string
            {
                return 'sqlite:' . $this->config->path;
            }

            public function reconnect(): void
            {
                parent::reconnect();
            }
        };

        $connection->disconnect();
        $this->assertFalse($connection->isConnected());

        $connection->reconnect();
        $this->assertTrue($connection->isConnected());
    }
}

/**
 * Test helper class that extends PdoConnection for testing purposes.
 */
class TestPdoConnection extends PdoConnection
{
    public function __construct(SqliteConfig $config, bool $connectImmediately = true)
    {
        $this->config = $config;
        if ($connectImmediately) {
            $this->connect($this->buildDsn(), $config, basename($config->path));
        }
    }

    protected function buildDsn(): string
    {
        return sprintf('sqlite:%s', $this->config->path);
    }
}
