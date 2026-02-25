<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Data;

use JardisAdapter\DbConnection\Data\SqliteConfig;
use PHPUnit\Framework\TestCase;

final class SqliteConfigTest extends TestCase
{
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
