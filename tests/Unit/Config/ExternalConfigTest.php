<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Config;

use JardisAdapter\DbConnection\Config\ExternalConfig;
use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;
use PHPUnit\Framework\TestCase;
use PDO;
use RuntimeException;

/**
 * Unit tests for ExternalConfig
 */
final class ExternalConfigTest extends TestCase
{
    public function testImplementsDatabaseConfigInterface(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $config = new ExternalConfig($pdo);

        $this->assertInstanceOf(DatabaseConfigInterface::class, $config);
    }

    public function testConstructorSetsPdo(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $config = new ExternalConfig($pdo);

        $this->assertSame($pdo, $config->pdo);
    }

    public function testGetDriverNameReturnsDriverFromPdo(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $config = new ExternalConfig($pdo);

        $this->assertSame('sqlite', $config->getDriverName());
    }

    public function testGetDsnThrowsException(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $config = new ExternalConfig($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot build DSN for externally managed connection');

        $config->getDsn();
    }

    public function testGetUserReturnsNull(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $config = new ExternalConfig($pdo);

        $this->assertNull($config->getUser());
    }

    public function testGetPasswordReturnsNull(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $config = new ExternalConfig($pdo);

        $this->assertNull($config->getPassword());
    }

    public function testGetOptionsReturnsEmptyArray(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $config = new ExternalConfig($pdo);

        $this->assertSame([], $config->getOptions());
    }

    public function testGetDatabaseNameDetectsSqlite(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $config = new ExternalConfig($pdo);

        $this->assertSame(':memory:', $config->getDatabaseName());
    }
}
