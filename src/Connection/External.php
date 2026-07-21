<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Connection;

use JardisAdapter\DbConnection\Config\ExternalConfig;
use RuntimeException;

/**
 * Wraps an externally managed PDO connection.
 *
 * This class allows integration with legacy systems or frameworks that provide
 * their own PDO instances. It extends PdoConnection to inherit all transaction
 * and connection management features while bypassing the normal connection
 * creation process.
 */
final class External extends PdoConnection
{
    private bool $manageLifecycle;

    /**
     * @param ExternalConfig $config The external connection configuration
     * @param bool $manageLifecycle If false, disconnect() will not close the PDO connection
     */
    public function __construct(ExternalConfig $config, bool $manageLifecycle = false)
    {
        parent::__construct($config);
        $this->manageLifecycle = $manageLifecycle;

        // Set the PDO instance directly without creating a new connection
        $this->pdo = $config->pdo;
    }

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        throw new RuntimeException(
            'External connection cannot be re-established. '
            . 'The external system must provide a new connection.'
        );
    }

    public function disconnect(): void
    {
        if ($this->manageLifecycle) {
            parent::disconnect();
        }
    }

    /**
     * Reconnection for externally managed connections.
     *
     * Performs a health check on the existing connection. If the connection is still
     * alive, it continues to use it. If the connection is dead, an exception is thrown
     * since we cannot recreate it (credentials are managed by the external system).
     *
     * @throws RuntimeException If the connection is dead and cannot be restored
     */
    public function reconnect(): void
    {
        if (!$this->isConnected()) {
            throw new RuntimeException(
                'External connection cannot be re-established. '
                . 'The external system must provide a new connection.'
            );
        }

        try {
            $stmt = $this->pdo()->query('SELECT 1');
            if ($stmt === false) {
                throw new RuntimeException(
                    'External connection health check failed. '
                    . 'The external system must provide a new connection.'
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new RuntimeException(
                'External connection is dead and cannot be restored. '
                . 'The external system must provide a new connection.',
                (int) $e->getCode(),
                $e
            );
        }
    }
}
