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
     * U2: Flag aus + offene Tx -> Routing unveraendert (Reader kommt).
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
     * U3: Flag an + offene Tx -> getReader() liefert dieselbe Instanz wie getWriter().
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
