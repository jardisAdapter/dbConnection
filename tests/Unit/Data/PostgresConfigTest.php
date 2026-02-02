<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Data;

use JardisAdapter\DbConnection\Data\PostgresConfig;
use PHPUnit\Framework\TestCase;

final class PostgresConfigTest extends TestCase
{
    public function testCanBeInstantiatedWithRequiredParameters(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame('localhost', $config->host);
        $this->assertSame('postgres', $config->user);
        $this->assertSame('secret', $config->password);
        $this->assertSame('testdb', $config->database);
    }

    public function testDefaultPortIs5432(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame(5432, $config->port);
    }

    public function testDefaultOptionsIsEmptyArray(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame([], $config->options);
    }

    public function testCanSetCustomPort(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'testdb',
            port: 5433
        );

        $this->assertSame(5433, $config->port);
    }

    public function testCanSetCustomOptions(): void
    {
        $options = [\PDO::ATTR_TIMEOUT => 5];
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'testdb',
            options: $options
        );

        $this->assertSame($options, $config->options);
    }

    public function testGetDriverNameReturnsPgsql(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame('pgsql', $config->getDriverName());
    }

    public function testPropertiesAreReadonly(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'testdb'
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $config->host = 'newhost';
    }
}
