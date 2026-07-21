<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Config;

use JardisAdapter\DbConnection\Config\MySqlConfig;
use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;
use PHPUnit\Framework\TestCase;

final class MySqlConfigTest extends TestCase
{
    public function testImplementsDatabaseConfigInterface(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertInstanceOf(DatabaseConfigInterface::class, $config);
    }

    public function testCanBeInstantiatedWithRequiredParameters(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame('localhost', $config->host);
        $this->assertSame('root', $config->user);
        $this->assertSame('secret', $config->password);
        $this->assertSame('testdb', $config->database);
    }

    public function testDefaultPortIs3306(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame(3306, $config->port);
    }

    public function testDefaultCharsetIsUtf8mb4(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame('utf8mb4', $config->charset);
    }

    public function testDefaultOptionsIsEmptyArray(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame([], $config->options);
    }

    public function testCanSetCustomPort(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb',
            port: 3307
        );

        $this->assertSame(3307, $config->port);
    }

    public function testCanSetCustomCharset(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb',
            charset: 'utf8'
        );

        $this->assertSame('utf8', $config->charset);
    }

    public function testCanSetCustomOptions(): void
    {
        $options = [\PDO::ATTR_TIMEOUT => 5];
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb',
            options: $options
        );

        $this->assertSame($options, $config->options);
    }

    public function testGetDriverNameReturnsMysql(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame('mysql', $config->getDriverName());
    }

    public function testGetDsnReturnsCorrectMysqlDsn(): void
    {
        $config = new MySqlConfig(
            host: 'myhost',
            user: 'root',
            password: 'secret',
            database: 'mydb',
            port: 3307,
            charset: 'utf8'
        );

        $this->assertSame('mysql:host=myhost;port=3307;dbname=mydb;charset=utf8', $config->getDsn());
    }

    public function testGetUserReturnsUser(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'myuser',
            password: 'secret',
            database: 'testdb'
        );

        $this->assertSame('myuser', $config->getUser());
    }

    public function testGetPasswordReturnsPassword(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'mypass',
            database: 'testdb'
        );

        $this->assertSame('mypass', $config->getPassword());
    }

    public function testGetOptionsReturnsOptions(): void
    {
        $options = [\PDO::ATTR_TIMEOUT => 10];
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb',
            options: $options
        );

        $this->assertSame($options, $config->getOptions());
    }

    public function testGetDatabaseNameReturnsDatabase(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'mydb'
        );

        $this->assertSame('mydb', $config->getDatabaseName());
    }

    public function testPropertiesAreReadonly(): void
    {
        $config = new MySqlConfig(
            host: 'localhost',
            user: 'root',
            password: 'secret',
            database: 'testdb'
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $config->host = 'newhost';
    }
}
