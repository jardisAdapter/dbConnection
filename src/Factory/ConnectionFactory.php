<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Factory;

use JardisAdapter\DbConnection\Config\ExternalConfig;
use JardisAdapter\DbConnection\Config\MySqlConfig;
use JardisAdapter\DbConnection\Config\PostgresConfig;
use JardisAdapter\DbConnection\Config\SqliteConfig;
use JardisAdapter\DbConnection\Connection\PdoConnection;
use JardisAdapter\DbConnection\Connection\External;
use JardisAdapter\DbConnection\Connection\SqLite;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PDO;

/**
 * Factory for creating database connections for all supported drivers.
 */
final class ConnectionFactory
{
    /**
     * @param array<int, mixed> $options
     */
    public function mysql(
        string $host,
        string $user,
        string $password,
        string $database,
        int $port = 3306,
        string $charset = 'utf8mb4',
        array $options = []
    ): DbConnectionInterface {
        $config = new MySqlConfig(
            host: $host,
            user: $user,
            password: $password,
            database: $database,
            port: $port,
            charset: $charset,
            options: $options,
        );
        $connection = new PdoConnection($config);
        $connection->connect();
        return $connection;
    }

    /**
     * @param array<int, mixed> $options
     */
    public function postgres(
        string $host,
        string $user,
        string $password,
        string $database,
        int $port = 5432,
        array $options = []
    ): DbConnectionInterface {
        $config = new PostgresConfig(
            host: $host,
            user: $user,
            password: $password,
            database: $database,
            port: $port,
            options: $options,
        );
        $connection = new PdoConnection($config);
        $connection->connect();
        return $connection;
    }

    /**
     * @param array<int, mixed> $options
     */
    public function sqlite(
        string $path = ':memory:',
        array $options = []
    ): DbConnectionInterface {
        return new SqLite(new SqliteConfig(
            path: $path,
            options: $options,
        ));
    }

    public function fromPdo(
        PDO $pdo,
        bool $manageLifecycle = false
    ): DbConnectionInterface {
        return new External(new ExternalConfig($pdo), $manageLifecycle);
    }
}
