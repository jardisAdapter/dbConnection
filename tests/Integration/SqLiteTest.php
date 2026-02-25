<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\SqLite;
use JardisAdapter\DbConnection\Data\SqliteConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration tests for SQLite PDO connection.
 */
final class SqLiteTest extends TestCase
{
    private ?SqLite $connection = null;
    private string $testDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDbPath = sys_get_temp_dir() . '/test_sqlite_' . uniqid() . '.db';
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

    public function testConnectionCanBeEstablished(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);

        $this->assertTrue($this->connection->isConnected());
        $this->assertSame('sqlite', $this->connection->getDriverName());
    }

    public function testInMemoryDatabaseWorks(): void
    {
        $config = new SqliteConfig(path: ':memory:');
        $this->connection = new SqLite($config);

        $this->assertTrue($this->connection->isConnected());
        $this->assertSame('sqlite', $this->connection->getDriverName());
    }

    public function testPdoReturnsValidPdoInstance(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testDisconnectClearsConnection(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $this->assertTrue($this->connection->isConnected());

        $this->connection->disconnect();

        $this->assertFalse($this->connection->isConnected());
    }

    public function testReconnectRestoresConnection(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $this->connection->disconnect();
        $this->assertFalse($this->connection->isConnected());

        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testGetDatabaseName(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $database = $this->connection->getDatabaseName();

        $this->assertSame(basename($this->testDbPath), $database);
    }

    public function testGetDatabasePath(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $path = $this->connection->getDatabasePath();

        $this->assertSame($this->testDbPath, $path);
    }

    public function testGetServerVersion(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $version = $this->connection->getServerVersion();

        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function testTransactionBeginCommit(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_table (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)');

        $this->connection->beginTransaction();
        $this->assertTrue($this->connection->inTransaction());

        $pdo->exec("INSERT INTO test_table (value) VALUES ('test')");

        $this->connection->commit();
        $this->assertFalse($this->connection->inTransaction());

        $stmt = $pdo->query('SELECT COUNT(*) FROM test_table');
        $count = $stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testTransactionRollback(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_table (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)');
        $pdo->exec('DELETE FROM test_table');

        $this->connection->beginTransaction();
        $pdo->exec("INSERT INTO test_table (value) VALUES ('test')");

        $this->connection->rollback();
        $this->assertFalse($this->connection->inTransaction());

        $stmt = $pdo->query('SELECT COUNT(*) FROM test_table');
        $count = $stmt->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testPdoThrowsExceptionWhenNotConnected(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->pdo();
    }

    public function testInvalidPathThrowsException(): void
    {
        $invalidPath = '/non/existent/path/database.db';
        $config = new SqliteConfig(path: $invalidPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to sqlite database');

        $this->connection = new SqLite($config);
    }

    public function testCustomPdoOptions(): void
    {
        $customOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];

        $config = new SqliteConfig(
            path: $this->testDbPath,
            options: $customOptions
        );

        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();
        $errorMode = $pdo->getAttribute(\PDO::ATTR_ERRMODE);

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    public function testForeignKeysAreEnabled(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();

        $stmt = $pdo->query('PRAGMA foreign_keys');
        $result = $stmt->fetchColumn();

        $this->assertSame(1, $result);
    }

    public function testWalModeIsEnabled(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();

        $stmt = $pdo->query('PRAGMA journal_mode');
        $result = $stmt->fetchColumn();

        $this->assertSame('wal', strtolower($result));
    }

    public function testVacuumExecutes(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE test_table (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO test_table (value) VALUES ('test')");
        $pdo->exec('DELETE FROM test_table');

        $this->connection->vacuum();

        $this->assertTrue(true);
    }

    public function testReconnectWithOptimizations(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $this->connection->disconnect();

        $this->connection->reconnect();

        $pdo = $this->connection->pdo();
        $stmt = $pdo->query('PRAGMA foreign_keys');
        $result = $stmt->fetchColumn();

        $this->assertSame(1, $result);
        $this->assertTrue($this->connection->isConnected());
    }

    public function testValidateDatabasePathWithNonWritableDirectory(): void
    {
        $invalidPath = '/root/readonly/database.db';
        $config = new SqliteConfig(path: $invalidPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to sqlite database');

        $this->connection = new SqLite($config);
    }

    public function testValidateDatabasePathWithNonReadableFile(): void
    {
        $readOnlyPath = sys_get_temp_dir() . '/readonly_' . uniqid() . '.db';
        touch($readOnlyPath);
        chmod($readOnlyPath, 0000);

        $config = new SqliteConfig(path: $readOnlyPath);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Could not connect to sqlite database');

            $this->connection = new SqLite($config);
        } finally {
            chmod($readOnlyPath, 0644);
            if (file_exists($readOnlyPath)) {
                unlink($readOnlyPath);
            }
        }
    }

    public function testReconnectRestoresOptimizations(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $this->connection->disconnect();

        $this->connection->reconnect();

        $pdo = $this->connection->pdo();

        $stmt = $pdo->query('PRAGMA foreign_keys');
        $result = $stmt->fetchColumn();
        $this->assertSame(1, $result);

        $stmt = $pdo->query('PRAGMA journal_mode');
        $result = $stmt->fetchColumn();
        $this->assertSame('wal', strtolower($result));
    }

    public function testReconnectAfterManualDisconnect(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);

        $this->connection->disconnect();
        $this->assertFalse($this->connection->isConnected());

        $this->connection->reconnect();
        $this->assertTrue($this->connection->isConnected());

        $pdo = $this->connection->pdo();
        $result = $pdo->query('SELECT 1')->fetch();
        $this->assertNotFalse($result);
    }

    public function testVacuumOnLargerDatabase(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE large_table (id INTEGER PRIMARY KEY, data TEXT)');
        for ($i = 0; $i < 100; $i++) {
            $pdo->exec("INSERT INTO large_table (data) VALUES ('data_$i')");
        }

        $pdo->exec('DELETE FROM large_table WHERE id % 2 = 0');

        $sizeBefore = filesize($this->testDbPath);

        $this->connection->vacuum();

        $sizeAfter = filesize($this->testDbPath);

        $this->assertGreaterThan(0, $sizeBefore);
        $this->assertGreaterThan(0, $sizeAfter);
    }

    public function testConnectionWithCustomOptions(): void
    {
        $customOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];

        $config = new SqliteConfig(
            path: $this->testDbPath,
            options: $customOptions
        );

        $this->connection = new SqLite($config);
        $pdo = $this->connection->pdo();

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testReconnectFailureWithInvalidPath(): void
    {
        $config = new SqliteConfig(path: $this->testDbPath);
        $this->connection = new SqLite($config);

        $this->connection->disconnect();

        unlink($this->testDbPath);
        $invalidDir = dirname($this->testDbPath) . '/nonexistent';
        $reflection = new \ReflectionClass($this->connection);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->connection, new SqliteConfig(path: $invalidDir . '/test.db'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite reconnection failed');

        $this->connection->reconnect();
    }
}
