<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Config;

use JardisAdapter\DbConnection\Config\PostgresConfig;
use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;
use PHPUnit\Framework\TestCase;

final class PostgresConfigTest extends TestCase
{
    public function testImplementsDatabaseConfigInterface(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertInstanceOf(DatabaseConfigInterface::class, $config);
    }

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

    public function testGetDsnReturnsCorrectPostgresDsn(): void
    {
        $config = new PostgresConfig(
            host: 'myhost',
            user: 'postgres',
            password: 'secret',
            database: 'mydb',
            port: 5433
        );

        $this->assertSame('pgsql:host=myhost;port=5433;dbname=mydb', $config->getDsn());
    }

    public function testGetUserReturnsUser(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'myuser',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame('myuser', $config->getUser());
    }

    public function testGetPasswordReturnsPassword(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'mypass',
            database: 'testdb'
        );

        $this->assertSame('mypass', $config->getPassword());
    }

    public function testGetDatabaseNameReturnsDatabase(): void
    {
        $config = new PostgresConfig(
            host: 'localhost',
            user: 'postgres',
            password: 'secret',
            database: 'mydb'
        );

        $this->assertSame('mydb', $config->getDatabaseName());
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
