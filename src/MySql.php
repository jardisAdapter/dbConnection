<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection;

use JardisAdapter\DbConnection\Data\MySqlConfig;
use JardisAdapter\DbConnection\Connection\PdoConnection;
use RuntimeException;

/**
 * MySQL database connection.
 */
final class MySql extends PdoConnection
{
    /**
     * @param MySqlConfig $config The MySQL connection configuration
     * @throws RuntimeException On connection error
     */
    public function __construct(MySqlConfig $config)
    {
        parent::__construct($config);
        $this->connect($this->buildDsn(), $config, $config->database);
    }

    protected function buildDsn(): string
    {
        assert($this->config instanceof MySqlConfig);

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config->host,
            $this->config->port,
            $this->config->database,
            $this->config->charset
        );
    }
}
