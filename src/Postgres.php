<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection;

use JardisAdapter\DbConnection\Data\PostgresConfig;
use JardisAdapter\DbConnection\Connection\PdoConnection;
use RuntimeException;

/**
 * PostgresSQL database connection.
 */
final class Postgres extends PdoConnection
{
    /**
     * @param PostgresConfig $config The PostgreSQL connection configuration
     * @throws RuntimeException On connection error
     */
    public function __construct(PostgresConfig $config)
    {
        parent::__construct($config);
        $this->connect($this->buildDsn(), $config, $config->database);
    }

    protected function buildDsn(): string
    {
        assert($this->config instanceof PostgresConfig);

        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->config->host,
            $this->config->port,
            $this->config->database
        );
    }
}
