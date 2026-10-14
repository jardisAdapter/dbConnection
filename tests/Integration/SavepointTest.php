<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Integration;

use InvalidArgumentException;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Savepoints (nested transaction brackets) against every supported driver.
 * SQLite always runs; MySQL, MariaDB and PostgreSQL run when their docker service is reachable.
 */
final class SavepointTest extends TestCase
{
    private const TABLE = 'savepoint_test';

    private ?DbConnectionInterface $connection = null;
    private ?string $sqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            try {
                $pdo = $this->connection->pdo();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
            } catch (\Throwable) {
            }
            $this->connection->disconnect();
            $this->connection = null;
        }
        if ($this->sqlitePath !== null && file_exists($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'mysql' => ['mysql'],
            'mariadb' => ['mariadb'],
            'postgres' => ['pgsql'],
        ];
    }

    #[DataProvider('drivers')]
    public function testConnectionImplementsSavepointCapability(string $driver): void
    {
        $this->assertInstanceOf(SavepointInterface::class, $this->open($driver));
    }

    #[DataProvider('drivers')]
    public function testRollbackToSavepointDiscardsOnlyTheNestedPart(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();
        $conn->pdo()->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('outer')");

        $conn->savepoint('inner_bracket');
        $conn->pdo()->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('inner')");
        $conn->rollbackToSavepoint('inner_bracket');

        $this->assertTrue($conn->inTransaction(), 'the enclosing transaction stays open');
        $conn->commit();

        $this->assertSame(['outer'], $this->labels($conn));
    }

    #[DataProvider('drivers')]
    public function testReleaseSavepointKeepsTheNestedPart(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();
        $conn->pdo()->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('outer')");

        $conn->savepoint('inner_bracket');
        $conn->pdo()->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('inner')");
        $conn->releaseSavepoint('inner_bracket');
        $conn->commit();

        $this->assertSame(['inner', 'outer'], $this->labels($conn));
    }

    #[DataProvider('drivers')]
    public function testOuterRollbackAlsoDiscardsAReleasedSavepoint(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();
        $conn->savepoint('inner_bracket');
        $conn->pdo()->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('inner')");
        $conn->releaseSavepoint('inner_bracket');
        $conn->rollback();

        $this->assertSame([], $this->labels($conn));
    }

    #[DataProvider('drivers')]
    public function testSavepointsNestDeeperThanOneLevel(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();
        $conn->savepoint('level_one');
        $conn->pdo()->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('one')");
        $conn->savepoint('level_two');
        $conn->pdo()->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('two')");
        $conn->rollbackToSavepoint('level_two');
        $conn->releaseSavepoint('level_one');
        $conn->commit();

        $this->assertSame(['one'], $this->labels($conn));
    }

    #[DataProvider('drivers')]
    public function testSavepointWithoutTransactionThrows(string $driver): void
    {
        $conn = $this->open($driver);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an active transaction');

        $conn->savepoint('no_transaction');
    }

    #[DataProvider('drivers')]
    public function testReleaseAndRollbackWithoutTransactionThrow(string $driver): void
    {
        $conn = $this->open($driver);

        foreach (['releaseSavepoint', 'rollbackToSavepoint'] as $method) {
            try {
                $conn->{$method}('no_transaction');
                $this->fail($method . ' must throw without an active transaction');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('requires an active transaction', $e->getMessage());
            }
        }
    }

    #[DataProvider('drivers')]
    public function testUnknownSavepointIsAStatementFailureNotASilentNoop(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();

        try {
            $this->expectException(RuntimeException::class);
            $conn->rollbackToSavepoint('never_opened');
        } finally {
            if ($conn->pdo()->inTransaction()) {
                $conn->rollback();
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'leading digit' => ['1abc'],
            'space' => ['a b'],
            'semicolon (injection)' => ['a; DROP TABLE users'],
            'quote' => ["a'b"],
            'dash' => ['a-b'],
            'trailing newline' => ["abc\n"],
            'umlaut' => ['größe'],
            '64 characters' => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameThrowsInvalidArgumentOnAllThreeMethods(string $name): void
    {
        $conn = $this->open('sqlite');
        $conn->beginTransaction();

        foreach (['savepoint', 'releaseSavepoint', 'rollbackToSavepoint'] as $method) {
            try {
                $conn->{$method}($name);
                $this->fail($method . ' accepted an invalid name');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $conn->rollback();
    }

    public function testNameIsValidatedBeforeTheTransactionCheck(): void
    {
        $conn = $this->open('sqlite');

        $this->expectException(InvalidArgumentException::class);

        $conn->savepoint('bad name');
    }

    public function testNameWith63CharactersIsAccepted(): void
    {
        $conn = $this->open('sqlite');
        $conn->beginTransaction();
        $name = str_repeat('a', 63);

        $conn->savepoint($name);
        $conn->releaseSavepoint($name);
        $conn->rollback();

        $this->assertSame([], $this->labels($conn));
    }

    public function testFromPdoConnectionSupportsSavepoints(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, DbConnectionInterface::DEFAULT_OPTIONS);
        $conn = (new ConnectionFactory())->fromPdo($pdo);
        $this->assertInstanceOf(SavepointInterface::class, $conn);

        $conn->beginTransaction();
        $conn->savepoint('external_bracket');
        $conn->rollbackToSavepoint('external_bracket');
        $conn->rollback();

        $this->assertFalse($conn->inTransaction());
    }

    private function open(string $driver): DbConnectionInterface
    {
        $factory = new ConnectionFactory();

        if ($driver === 'sqlite') {
            $this->sqlitePath = sys_get_temp_dir() . '/test_savepoint_' . uniqid() . '.db';
            $conn = $factory->sqlite($this->sqlitePath);
        } elseif ($driver === 'pgsql') {
            $conn = $this->tryOpen(fn() => $factory->postgres(
                getenv('POSTGRES_HOST') ?: 'postgres',
                getenv('POSTGRES_USER') ?: 'test_user',
                getenv('POSTGRES_PASSWORD') ?: 'test_password',
                getenv('POSTGRES_DATABASE') ?: 'test_db',
                (int) (getenv('POSTGRES_PORT') ?: 5432)
            ), 'PostgreSQL');
        } else {
            $prefix = $driver === 'mariadb' ? 'MARIADB' : 'MYSQL';
            $conn = $this->tryOpen(fn() => $factory->mysql(
                getenv($prefix . '_HOST') ?: ($driver === 'mariadb' ? 'mariadb' : 'mysql'),
                getenv($prefix . '_USER') ?: 'test_user',
                getenv($prefix . '_PASSWORD') ?: 'test_password',
                getenv($prefix . '_DATABASE') ?: 'test_db',
                (int) (getenv($prefix . '_PORT') ?: 3306)
            ), $driver);
        }

        $this->connection = $conn;
        $pdo = $conn->pdo();
        $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $idColumn = match ($conn->getDriverName()) {
            'pgsql' => 'id SERIAL PRIMARY KEY',
            'sqlite' => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'id INT AUTO_INCREMENT PRIMARY KEY',
        };
        // InnoDB (MySQL/MariaDB default) is transactional; the table is created outside any transaction.
        $pdo->exec('CREATE TABLE ' . self::TABLE . " ($idColumn, label VARCHAR(32) NOT NULL)");

        return $conn;
    }

    /**
     * @param callable(): DbConnectionInterface $open
     */
    private function tryOpen(callable $open, string $label): DbConnectionInterface
    {
        try {
            $connection = $open();
            $connection->connect();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped($label . ' server is not available: ' . $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function labels(DbConnectionInterface $conn): array
    {
        $stmt = $conn->pdo()->query('SELECT label FROM ' . self::TABLE . ' ORDER BY label');
        $this->assertNotFalse($stmt);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
