<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\Postgres;
use JardisAdapter\DbConnection\Data\PostgresConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration tests for PostgreSQL PDO connection.
 * Requires running PostgreSQL Docker container from docker-compose.yml
 */
final class PostgresTest extends TestCase
{
    private ?Postgres $connection = null;
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
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);

        $this->assertTrue($this->connection->isConnected());
        $this->assertSame('pgsql', $this->connection->getDriverName());
    }

    public function testPdoReturnsValidPdoInstance(): void
    {
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);
        $pdo = $this->connection->pdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame('pgsql', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testDisconnectClearsConnection(): void
    {
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);
        $this->assertTrue($this->connection->isConnected());

        $this->connection->disconnect();

        $this->assertFalse($this->connection->isConnected());
    }

    public function testReconnectRestoresConnection(): void
    {
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);
        $this->connection->disconnect();
        $this->assertFalse($this->connection->isConnected());

        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testGetDatabaseName(): void
    {
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);
        $database = $this->connection->getDatabaseName();

        $this->assertSame($this->database, $database);
    }

    public function testGetServerVersion(): void
    {
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);
        $version = $this->connection->getServerVersion();

        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+/', $version);
    }

    public function testTransactionBeginCommit(): void
    {
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);
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
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);
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
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new Postgres($config);
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->pdo();
    }

    public function testInvalidCredentialsThrowException(): void
    {
        $config = new PostgresConfig(
            host: $this->host,
            user: 'invalid_user',
            password: 'invalid_password',
            database: $this->database,
            port: $this->port
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to pgsql database');

        $this->connection = new Postgres($config);
    }

    public function testInvalidDatabaseThrowsException(): void
    {
        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: 'non_existent_database',
            port: $this->port
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to pgsql database');

        $this->connection = new Postgres($config);
    }

    public function testCustomPdoOptions(): void
    {
        $customOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];

        $config = new PostgresConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port,
            options: $customOptions
        );

        $this->connection = new Postgres($config);
        $pdo = $this->connection->pdo();
        $errorMode = $pdo->getAttribute(\PDO::ATTR_ERRMODE);

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    private function isPostgresAvailable(): bool
    {
        try {
            $config = new PostgresConfig(
                host: $this->host,
                user: $this->user,
                password: $this->password,
                database: $this->database,
                port: $this->port
            );
            $connection = new Postgres($config);
            $available = $connection->isConnected();
            $connection->disconnect();
            return $available;
        } catch (\Exception $e) {
            return false;
        }
    }
}
