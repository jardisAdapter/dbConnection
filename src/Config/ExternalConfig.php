<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Config;

use JardisSupport\Contract\DbConnection\DatabaseConfigInterface;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Configuration for wrapping an externally managed PDO connection.
 * Used when integrating with legacy systems or frameworks that provide their own PDO instances.
 */
final readonly class ExternalConfig implements DatabaseConfigInterface
{
    public string $databaseName;

    /**
     * @param PDO $pdo The existing PDO connection from an external system
     */
    public function __construct(
        public PDO $pdo
    ) {
        $this->databaseName = self::detectDatabaseName($pdo, $this->getDriverName());
    }

    /**
     * @throws RuntimeException Always — external connections have no DSN
     */
    public function getDsn(): string
    {
        throw new RuntimeException(
            'Cannot build DSN for externally managed connection. '
            . 'This connection wraps an existing PDO instance.'
        );
    }

    public function getUser(): ?string
    {
        return null;
    }

    public function getPassword(): ?string
    {
        return null;
    }

    /**
     * @return array<int, mixed>
     */
    public function getOptions(): array
    {
        return [];
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getDriverName(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private static function detectDatabaseName(PDO $pdo, string $driver): string
    {
        try {
            if ($driver === 'mysql') {
                $stmt = $pdo->query('SELECT DATABASE()');
                $result = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
                return (string) ($result[0] ?? 'unknown');
            }

            if ($driver === 'pgsql') {
                $stmt = $pdo->query('SELECT current_database()');
                $result = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
                return (string) ($result[0] ?? 'unknown');
            }

            if ($driver === 'sqlite') {
                $stmt = $pdo->query('PRAGMA database_list');
                $result = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
                $file = isset($result[2]) ? (string) $result[2] : '';
                return $file !== '' ? basename($file) : ':memory:';
            }

            return 'unknown';
        } catch (PDOException $e) {
            return 'unknown';
        }
    }
}
