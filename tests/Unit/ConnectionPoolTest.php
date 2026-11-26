<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the sticky-writer-during-transaction routing in ConnectionPool::getReader().
 *
 * Uses in-memory SQLite connections (no external Docker service required) to exercise the
 * pure routing logic — instance identity and exception behavior, not cross-connection data
 * visibility. Real cross-driver visibility is covered by the Integration suite (I1).
 */
class ConnectionPoolTest extends TestCase
{
    private ConnectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ConnectionFactory();
    }

    /**
     * Contract A5-S1: pool without config (package default) + open writer Tx -> getReader()
     * returns the writer.
     */
    public function testGetReaderReturnsWriterDuringOpenTransactionWithoutConfig(): void
    {
        $writer = $this->factory->sqlite();
        $reader = $this->factory->sqlite();

        $pool = new ConnectionPool(writer: $writer, readers: [$reader]);

        $writer->beginTransaction();

        $this->assertSame($writer, $pool->getReader());

        $writer->rollback();
    }

    /**
     * Contract A5-S2: pool without config (package default) + no open Tx -> getReader()
     * returns the reader, as before.
     */
    public function testGetReaderReturnsReaderWithoutTransactionWithoutConfig(): void
    {
        $writer = $this->factory->sqlite();
        $reader = $this->factory->sqlite();

        $pool = new ConnectionPool(writer: $writer, readers: [$reader]);

        $this->assertSame($reader, $pool->getReader());
    }

    /**
     * Contract A5-S5: config sets other values but does not name the flag + open writer Tx
     * -> getReader() returns the writer (the package default applies).
     */
    public function testGetReaderReturnsWriterDuringOpenTransactionWhenConfigOmitsFlag(): void
    {
        $writer = $this->factory->sqlite();
        $reader = $this->factory->sqlite();

        $pool = new ConnectionPool(
            writer: $writer,
            readers: [$reader],
            config: new ConnectionPoolConfig(
                validateConnections: false,
                healthCheckCacheTtl: 60,
                loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_RANDOM,
            )
        );

        $writer->beginTransaction();

        $this->assertSame($writer, $pool->getReader());

        $writer->rollback();
    }

    /**
     * Contract A5-S3 (U2): flag explicitly false + open Tx -> routing unchanged (reader comes).
     */
    public function testGetReaderIgnoresOpenWriterTransactionWhenFlagDisabled(): void
    {
        $writer = $this->factory->sqlite();
        $reader = $this->factory->sqlite();

        $pool = new ConnectionPool(
            writer: $writer,
            readers: [$reader],
            config: new ConnectionPoolConfig(validateConnections: false, stickyWriterDuringTransaction: false)
        );

        $writer->beginTransaction();

        $this->assertSame($reader, $pool->getReader());

        $writer->rollback();
    }

    /**
     * Contract A5-S4 (U3): flag explicitly true + open Tx -> getReader() returns the same
     * instance as getWriter().
     */
    public function testGetReaderReturnsWriterInstanceWhenStickyFlagEnabledDuringOpenTransaction(): void
    {
        $writer = $this->factory->sqlite();
        $reader = $this->factory->sqlite();

        $pool = new ConnectionPool(
            writer: $writer,
            readers: [$reader],
            config: new ConnectionPoolConfig(validateConnections: false, stickyWriterDuringTransaction: true)
        );

        $writer->beginTransaction();

        $this->assertSame($pool->getWriter(), $pool->getReader());
        $this->assertNotSame($reader, $pool->getReader());

        $writer->rollback();
    }

    /**
     * U4 (commit end): Flag an, Tx committed -> Routing kehrt zu Round-Robin zurueck.
     */
    public function testStickyWriterRoutingEndsAfterCommit(): void
    {
        $writer = $this->factory->sqlite();
        $reader = $this->factory->sqlite();

        $pool = new ConnectionPool(
            writer: $writer,
            readers: [$reader],
            config: new ConnectionPoolConfig(validateConnections: false, stickyWriterDuringTransaction: true)
        );

        $writer->beginTransaction();
        $this->assertSame($writer, $pool->getReader());

        $writer->commit();

        $this->assertSame($reader, $pool->getReader());
    }

    /**
     * U4 (rollback end): Flag an, Tx rolled back -> Routing kehrt zu Round-Robin zurueck.
     */
    public function testStickyWriterRoutingEndsAfterRollback(): void
    {
        $writer = $this->factory->sqlite();
        $reader = $this->factory->sqlite();

        $pool = new ConnectionPool(
            writer: $writer,
            readers: [$reader],
            config: new ConnectionPoolConfig(validateConnections: false, stickyWriterDuringTransaction: true)
        );

        $writer->beginTransaction();
        $this->assertSame($writer, $pool->getReader());

        $writer->rollback();

        $this->assertSame($reader, $pool->getReader());
    }

    /**
     * U5: Flag an, keine Tx -> normales Routing (Reader kommt).
     */
    public function testGetReaderUsesNormalRoutingWhenStickyFlagEnabledButNoOpenTransaction(): void
    {
        $writer = $this->factory->sqlite();
        $reader = $this->factory->sqlite();

        $pool = new ConnectionPool(
            writer: $writer,
            readers: [$reader],
            config: new ConnectionPoolConfig(validateConnections: false, stickyWriterDuringTransaction: true)
        );

        $this->assertSame($reader, $pool->getReader());
    }

    /**
     * U6: Flag an + leere Reader-Liste -> Verhalten wie bisher (Writer-Fallback),
     * unabhaengig davon, ob eine Tx offen ist.
     */
    public function testStickyFlagWithEmptyReaderListStillFallsBackToWriter(): void
    {
        $writer = $this->factory->sqlite();

        $pool = new ConnectionPool(
            writer: $writer,
            readers: [],
            config: new ConnectionPoolConfig(validateConnections: false, stickyWriterDuringTransaction: true)
        );

        $this->assertSame($writer, $pool->getReader());

        $writer->beginTransaction();
        $this->assertSame($writer, $pool->getReader());
        $writer->rollback();
    }

    /**
     * U7: Flag an + offene Tx + Writer unhealthy -> dieselbe RuntimeException wie getWriter().
     */
    public function testGetReaderThrowsSameExceptionAsGetWriterWhenWriterUnhealthyDuringStickyTransaction(): void
    {
        $writer = $this->createMock(DbConnectionInterface::class);
        $writer->method('inTransaction')->willReturn(true);
        $writer->method('pdo')->willThrowException(new RuntimeException('connection lost'));

        $pool = new ConnectionPool(
            writer: $writer,
            config: new ConnectionPoolConfig(validateConnections: true, stickyWriterDuringTransaction: true)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Writer connection health check failed');

        $pool->getReader();
    }
}
