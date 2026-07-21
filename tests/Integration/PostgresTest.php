<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\Connection\PdoConnection;
use JardisAdapter\DbConnection\Config\PostgresConfig;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration tests for PostgreSQL PDO connection.
 * Requires running PostgreSQL Docker container from docker-compose.yml
 */
final class PostgresTest extends TestCase
{
    private ?DbConnectionInterface $connection = null;
    private string $host;
    private int $port;
    private string $database;
    private string $user;
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = getenv('POSTGRES_HOST') ?: 'postgres';
        $this->port = (int)(getenv('POSTGRES_PORT') ?: 5432);
        $this->database = getenv('POSTGRES_DB') ?: 'test_db';
        $this->user = getenv('POSTGRES_USER') ?: 'test_user';
        $this->password = getenv('POSTGRES_PASSWORD') ?: 'test_password';

        if (!$this->isPostgresAvailable()) {
            $this->markTestSkipped('PostgreSQL server is not available');
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            try {
                $pdo = $this->connection->pdo();
                $pdo->exec('DROP TABLE IF EXISTS test_table');
            } catch (\Exception $e) {
            }
            $this->connection->disconnect();
            $this->connection = null;
        }

        parent::tearDown();
    }

    public function testConnectionCanBeEstablished(): void
    {
        $this->connection = $this->createConnection();

        $this->assertTrue($this->connection->isConnected());
        $this->assertSame('pgsql', $this->connection->getDriverName());
    }

    public function testPdoReturnsValidPdoInstance(): void
    {
        $this->connection = $this->createConnection();
        $pdo = $this->connection->pdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame('pgsql', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
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
        $this->assertFalse($this->connection->isConnected());

        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testGetDatabaseName(): void
    {
        $this->connection = $this->createConnection();
        $database = $this->connection->getDatabaseName();

        $this->assertSame($this->database, $database);
    }

    public function testGetServerVersion(): void
    {
        $this->connection = $this->createConnection();
        $version = $this->connection->getServerVersion();

        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+/', $version);
    }

    public function testTransactionBeginCommit(): void
    {
        $this->connection = $this->createConnection();
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_table (id SERIAL PRIMARY KEY, value VARCHAR(255))');

        $this->connection->beginTransaction();
        $this->assertTrue($this->connection->inTransaction());

        $pdo->exec("INSERT INTO test_table (value) VALUES ('test')");

        $this->connection->commit();
        $this->assertFalse($this->connection->inTransaction());

        $stmt = $pdo->query('SELECT COUNT(*) FROM test_table');
        $count = $stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $count);

        $pdo->exec('DROP TABLE test_table');
    }

    public function testTransactionRollback(): void
    {
        $this->connection = $this->createConnection();
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_table (id SERIAL PRIMARY KEY, value VARCHAR(255))');
        $pdo->exec('TRUNCATE TABLE test_table');

        $this->connection->beginTransaction();
        $pdo->exec("INSERT INTO test_table (value) VALUES ('test')");

        $this->connection->rollback();
        $this->assertFalse($this->connection->inTransaction());

        $stmt = $pdo->query('SELECT COUNT(*) FROM test_table');
        $count = $stmt->fetchColumn();
        $this->assertSame(0, $count);

        $pdo->exec('DROP TABLE test_table');
    }

    public function testPdoThrowsExceptionWhenNotConnected(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->pdo();
    }

    public function testInvalidCredentialsThrowException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to pgsql database');

        $factory = new ConnectionFactory();
        $this->connection = $factory->postgres(
            $this->host,
            'invalid_user',
            'invalid_password',
            $this->database,
            $this->port
        );
    }

    public function testInvalidDatabaseThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to pgsql database');

        $factory = new ConnectionFactory();
        $this->connection = $factory->postgres(
            $this->host,
            $this->user,
            $this->password,
            'non_existent_database',
            $this->port
        );
    }

    public function testCustomPdoOptions(): void
    {
        $customOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];

        $factory = new ConnectionFactory();
        $this->connection = $factory->postgres(
            $this->host,
            $this->user,
            $this->password,
            $this->database,
            $this->port,
            options: $customOptions
        );
        $pdo = $this->connection->pdo();
        $errorMode = $pdo->getAttribute(\PDO::ATTR_ERRMODE);

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    private function createConnection(): DbConnectionInterface
    {
        $factory = new ConnectionFactory();
        return $factory->postgres(
            $this->host,
            $this->user,
            $this->password,
            $this->database,
            $this->port
        );
    }

    private function isPostgresAvailable(): bool
    {
        try {
            $connection = $this->createConnection();
            $available = $connection->isConnected();
            $connection->disconnect();
            return $available;
        } catch (\Exception $e) {
            return false;
        }
    }
}
