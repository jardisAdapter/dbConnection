<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\MySql;
use JardisAdapter\DbConnection\Data\MySqlConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration tests for MySQL PDO connection.
 * Requires running MySQL Docker container from docker-compose.yml
 */
final class MySqlTest extends TestCase
{
    private ?MySql $connection = null;
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
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);

        $this->assertTrue($this->connection->isConnected());
        $this->assertSame('mysql', $this->connection->getDriverName());
    }

    public function testPdoReturnsValidPdoInstance(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $pdo = $this->connection->pdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame('mysql', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testDisconnectClearsConnection(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $this->assertTrue($this->connection->isConnected());

        $this->connection->disconnect();

        $this->assertFalse($this->connection->isConnected());
    }

    public function testReconnectRestoresConnection(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $this->connection->disconnect();
        $this->assertFalse($this->connection->isConnected());

        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testGetDatabaseName(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $database = $this->connection->getDatabaseName();

        $this->assertSame($this->database, $database);
    }

    public function testGetServerVersion(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $version = $this->connection->getServerVersion();

        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function testTransactionBeginCommit(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_table (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(255))');

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
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_table (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(255))');
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
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $this->connection->disconnect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active database connection');

        $this->connection->pdo();
    }

    public function testInvalidCredentialsThrowException(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: 'invalid_user',
            password: 'invalid_password',
            database: $this->database,
            port: $this->port
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to mysql database');

        $this->connection = new MySql($config);
    }

    public function testInvalidDatabaseThrowsException(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: 'non_existent_database',
            port: $this->port
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to mysql database');

        $this->connection = new MySql($config);
    }

    public function testCustomCharset(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port,
            charset: 'utf8'
        );

        $this->connection = new MySql($config);
        $pdo = $this->connection->pdo();
        $stmt = $pdo->query("SHOW VARIABLES LIKE 'character_set_client'");
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('utf8mb3', $result['Value'] ?? $result['value']);
    }

    public function testDefaultCharsetIsUtf8mb4(): void
    {
        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port
        );

        $this->connection = new MySql($config);
        $pdo = $this->connection->pdo();
        $stmt = $pdo->query("SHOW VARIABLES LIKE 'character_set_client'");
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('utf8mb4', $result['Value'] ?? $result['value']);
    }

    public function testCustomPdoOptions(): void
    {
        $customOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ];

        $config = new MySqlConfig(
            host: $this->host,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            port: $this->port,
            options: $customOptions
        );

        $this->connection = new MySql($config);
        $pdo = $this->connection->pdo();
        $errorMode = $pdo->getAttribute(\PDO::ATTR_ERRMODE);

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $errorMode);
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
