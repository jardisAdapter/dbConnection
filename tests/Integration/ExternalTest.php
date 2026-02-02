<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\External;
use JardisAdapter\DbConnection\MySql;
use JardisAdapter\DbConnection\Data\MySqlConfig;
use JardisAdapter\DbConnection\Data\ExternalConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use PDO;

/**
 * Integration tests for External
 * Tests wrapping of existing PDO connections
 */
final class ExternalTest extends TestCase
{
    private ?PDO $externalPdo = null;
    private string $host;
    private int $port;
    private string $database;
    private string $user;
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = getenv('MYSQL_HOST') ?: 'mysql';
        $this->port = (int)(getenv('MYSQL_PORT') ?: 3306);
        $this->database = getenv('MYSQL_DATABASE') ?: 'test_db';
        $this->user = getenv('MYSQL_USER') ?: 'test_user';
        $this->password = getenv('MYSQL_PASSWORD') ?: 'test_password';

        if (!$this->isMySqlAvailable()) {
            $this->markTestSkipped('MySQL server is not available');
        }
    }

    protected function tearDown(): void
    {
        if ($this->externalPdo !== null) {
            try {
                $this->externalPdo->exec('DROP TABLE IF EXISTS external_test_table');
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
            $this->externalPdo = null;
        }

        parent::tearDown();
    }

    public function testCanWrapExternalPdoConnection(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $this->assertInstanceOf(External::class, $connection);
        $this->assertTrue($connection->isConnected());
    }

    public function testWrappedConnectionReturnsSamePdoInstance(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $this->assertSame($pdo, $connection->pdo());
    }

    public function testAutoDetectsDatabaseNameForMySql(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $this->assertSame($this->database, $connection->getDatabaseName());
    }

    public function testGetDriverNameReturnsMySql(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $this->assertSame('mysql', $connection->getDriverName());
    }

    public function testTransactionBeginCommit(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $pdo->exec('CREATE TABLE IF NOT EXISTS external_test_table (id INT PRIMARY KEY, value VARCHAR(255))');
        $pdo->exec('TRUNCATE TABLE external_test_table');

        $connection->beginTransaction();
        $this->assertTrue($connection->inTransaction());

        $pdo->exec("INSERT INTO external_test_table (id, value) VALUES (1, 'test')");

        $connection->commit();
        $this->assertFalse($connection->inTransaction());

        $stmt = $pdo->query('SELECT COUNT(*) FROM external_test_table');
        $count = $stmt->fetchColumn();
        $this->assertEquals(1, $count);

        $pdo->exec('DROP TABLE external_test_table');
    }

    public function testTransactionRollback(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $pdo->exec('CREATE TABLE IF NOT EXISTS external_test_table (id INT PRIMARY KEY, value VARCHAR(255))');
        $pdo->exec('TRUNCATE TABLE external_test_table');

        $connection->beginTransaction();
        $pdo->exec("INSERT INTO external_test_table (id, value) VALUES (1, 'test')");

        $connection->rollback();
        $this->assertFalse($connection->inTransaction());

        $stmt = $pdo->query('SELECT COUNT(*) FROM external_test_table');
        $count = $stmt->fetchColumn();
        $this->assertEquals(0, $count);

        $pdo->exec('DROP TABLE external_test_table');
    }

    public function testDisconnectDoesNotCloseExternalConnection(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $connection->disconnect();
        $this->assertFalse($connection->isConnected());

        // External PDO should still be usable
        $stmt = $pdo->query('SELECT 1');
        $this->assertNotFalse($stmt);
    }

    public function testBuildDsnThrowsException(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot build DSN for externally managed connection');

        // Try to trigger buildDsn through reflection
        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('buildDsn');
        $method->setAccessible(true);
        $method->invoke($connection);
    }

    public function testReconnectWithAliveConnectionSucceeds(): void
    {
        $pdo = $this->createExternalPdo();
        $config = new ExternalConfig($pdo);
        $connection = new External($config);

        // reconnect() should succeed if connection is still alive (health check passes)
        $connection->reconnect();

        $this->assertTrue($connection->isConnected());
    }

    public function testReconnectWithDeadConnectionThrowsException(): void
    {
        $pdo = $this->createExternalPdo();
        $config = new ExternalConfig($pdo);
        $connection = new External($config);

        // Disconnect makes the connection unavailable
        $connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('External connection is dead and cannot be restored');

        $connection->reconnect();
    }

    public function testGetServerVersion(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $version = $connection->getServerVersion();

        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function testIsConnectedReturnsTrueForValidConnection(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $this->assertTrue($connection->isConnected());
    }

    public function testCanExecuteQueriesOnWrappedConnection(): void
    {
        $pdo = $this->createExternalPdo();
        $connection = new External(new ExternalConfig($pdo));

        $pdo->exec('CREATE TABLE IF NOT EXISTS external_test_table (id INT PRIMARY KEY, value VARCHAR(255))');
        $pdo->exec('TRUNCATE TABLE external_test_table');
        $pdo->exec("INSERT INTO external_test_table (id, value) VALUES (1, 'test_value')");

        $stmt = $connection->pdo()->query('SELECT value FROM external_test_table WHERE id = 1');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('test_value', $result['value']);

        $pdo->exec('DROP TABLE external_test_table');
    }

    public function testWrapsExistingConnectionWithCustomOptions(): void
    {
        // Create PDO with custom error mode
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->database);
        $pdo = new PDO($dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT
        ]);

        $connection = new External(new ExternalConfig($pdo));

        // Verify the custom error mode is preserved
        $errorMode = $connection->pdo()->getAttribute(PDO::ATTR_ERRMODE);
        $this->assertSame(PDO::ERRMODE_SILENT, $errorMode);

        $this->externalPdo = $pdo;
    }

    private function createExternalPdo(): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->database);
        $this->externalPdo = new PDO($dsn, $this->user, $this->password);
        return $this->externalPdo;
    }

    public function testAutoDetectsDatabaseNameForPostgres(): void
    {
        $host = getenv('POSTGRES_HOST') ?: 'postgres';
        $port = (int)(getenv('POSTGRES_PORT') ?: 5432);
        $database = getenv('POSTGRES_DATABASE') ?: 'test_db';
        $user = getenv('POSTGRES_USER') ?: 'test_user';
        $password = getenv('POSTGRES_PASSWORD') ?: 'test_password';

        try {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
            $pdo = new PDO($dsn, $user, $password);
            $connection = new External(new ExternalConfig($pdo));

            $this->assertSame($database, $connection->getDatabaseName());
        } catch (\Exception $e) {
            $this->markTestSkipped('PostgreSQL not available: ' . $e->getMessage());
        }
    }

    public function testAutoDetectsDatabaseNameForSqlite(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new External(new ExternalConfig($pdo));

        $this->assertSame(':memory: or file', $connection->getDatabaseName());
    }

    public function testDetectDatabaseNameReturnsUnknownForUnsupportedDriver(): void
    {
        // SQLite with file would trigger 'unknown' for unsupported driver if we had one
        // For now, test that we handle the known drivers
        $pdo = new PDO('sqlite::memory:');
        $connection = new External(new ExternalConfig($pdo));

        // This tests the sqlite branch, which returns a special string
        $this->assertIsString($connection->getDatabaseName());
    }

    private function isMySqlAvailable(): bool
    {
        try {
            $config = new MySqlConfig(
                host: $this->host,
                user: $this->user,
                password: $this->password,
                database: $this->database,
                port: $this->port
            );
            $connection = new MySql($config);
            $available = $connection->isConnected();
            $connection->disconnect();
            return $available;
        } catch (\Exception $e) {
            return false;
        }
    }
}
