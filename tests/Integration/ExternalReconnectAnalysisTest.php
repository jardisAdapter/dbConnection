<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use JardisAdapter\DbConnection\External;
use JardisAdapter\DbConnection\Data\ExternalConfig;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Analysis test for External reconnect behavior
 * This test demonstrates how External's reconnect() works with health checks
 */
final class ExternalReconnectAnalysisTest extends TestCase
{
    public function testReconnectWithAliveConnectionPerformsHealthCheck(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s',
            getenv('MYSQL_HOST') ?: 'mysql',
            (int)(getenv('MYSQL_PORT') ?: 3306),
            getenv('MYSQL_DATABASE') ?: 'test_db'
        );
        $pdo = new PDO(
            $dsn,
            getenv('MYSQL_USER') ?: 'test_user',
            getenv('MYSQL_PASSWORD') ?: 'test_password'
        );

        $config = new ExternalConfig($pdo);
        $connection = new External($config);

        // reconnect() performs health check and succeeds if connection is alive
        $connection->reconnect();

        $this->assertTrue($connection->isConnected());
    }

    public function testReconnectCannotRestoreDeadConnection(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s',
            getenv('MYSQL_HOST') ?: 'mysql',
            (int)(getenv('MYSQL_PORT') ?: 3306),
            getenv('MYSQL_DATABASE') ?: 'test_db'
        );
        $pdo = new PDO(
            $dsn,
            getenv('MYSQL_USER') ?: 'test_user',
            getenv('MYSQL_PASSWORD') ?: 'test_password'
        );

        $config = new ExternalConfig($pdo);
        $connection = new External($config);

        // Disconnect the external PDO
        $connection->disconnect();

        // External connection cannot be restored - external system must handle this
        $this->assertFalse($connection->isConnected());

        // reconnect() throws exception because connection is dead
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('External connection is dead and cannot be restored');

        $connection->reconnect();
    }
}
