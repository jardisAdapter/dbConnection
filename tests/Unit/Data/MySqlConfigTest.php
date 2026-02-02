<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Data;

use JardisAdapter\DbConnection\Data\MySqlConfig;
use PHPUnit\Framework\TestCase;

final class MySqlConfigTest extends TestCase
{
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
