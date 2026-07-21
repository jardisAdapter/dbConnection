<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Config;

use JardisAdapter\DbConnection\Config\SqliteConfig;
use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;
use PHPUnit\Framework\TestCase;

final class SqliteConfigTest extends TestCase
{
    public function testImplementsDatabaseConfigInterface(): void
    {
        $config = new SqliteConfig(path: '/path/to/database.db');

        $this->assertInstanceOf(DatabaseConfigInterface::class, $config);
    }

    public function testCanBeInstantiatedWithRequiredParameters(): void
    {
        $config = new SqliteConfig(path: '/path/to/database.db');

        $this->assertSame('/path/to/database.db', $config->path);
    }

    public function testDefaultOptionsIsEmptyArray(): void
    {
        $config = new SqliteConfig(path: '/path/to/database.db');

        $this->assertSame([], $config->options);
    }

    public function testCanSetCustomOptions(): void
    {
        $options = [\PDO::ATTR_TIMEOUT => 5];
        $config = new SqliteConfig(
            path: '/path/to/database.db',
            options: $options
        );

        $this->assertSame($options, $config->options);
    }

    public function testGetDriverNameReturnsSqlite(): void
    {
        $config = new SqliteConfig(path: '/path/to/database.db');

        $this->assertSame('sqlite', $config->getDriverName());
    }

    public function testGetDsnReturnsCorrectSqliteDsn(): void
    {
        $config = new SqliteConfig(path: '/var/db/app.db');

        $this->assertSame('sqlite:/var/db/app.db', $config->getDsn());
    }

    public function testGetDsnForMemory(): void
    {
        $config = new SqliteConfig(path: ':memory:');

        $this->assertSame('sqlite::memory:', $config->getDsn());
    }

    public function testGetUserReturnsNull(): void
    {
        $config = new SqliteConfig(path: ':memory:');

        $this->assertNull($config->getUser());
    }

    public function testGetPasswordReturnsNull(): void
    {
        $config = new SqliteConfig(path: ':memory:');

        $this->assertNull($config->getPassword());
    }

    public function testGetDatabaseNameReturnsBasename(): void
    {
        $config = new SqliteConfig(path: '/var/db/app.db');

        $this->assertSame('app.db', $config->getDatabaseName());
    }

    public function testGetDatabaseNameForMemory(): void
    {
        $config = new SqliteConfig(path: ':memory:');

        $this->assertSame(':memory:', $config->getDatabaseName());
    }

    public function testSupportsInMemoryPath(): void
    {
        $config = new SqliteConfig(path: ':memory:');

        $this->assertSame(':memory:', $config->path);
    }

    public function testPropertiesAreReadonly(): void
    {
        $config = new SqliteConfig(path: '/path/to/database.db');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $config->path = '/new/path.db';
    }
}
