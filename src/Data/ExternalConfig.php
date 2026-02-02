<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Data;

use PDO;

/**
 * Configuration for wrapping an externally managed PDO connection.
 * Used when integrating with legacy systems or frameworks that provide their own PDO instances.
 */
final readonly class ExternalConfig implements DatabaseConfig
{
    /**
     * @param PDO $pdo The existing PDO connection from an external system
     */
    public function __construct(
        public PDO $pdo
    ) {
    }

    public function getDriverName(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
