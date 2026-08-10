# Jardis DbConnection

![Build Status](https://github.com/jardisAdapter/dbConnection/actions/workflows/ci.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](phpcs.xml)
[![Coverage](https://img.shields.io/badge/Coverage-89.49%25-green.svg)](https://github.com/jardisAdapter/dbConnection)

> Part of **[Jardis](https://jardis.io)** — the Domain-Driven Design platform for PHP. You model your domain; Jardis generates the production-ready hexagonal code (DTOs, Command/Query handlers, repositories, persistence). This package is part of the open-source foundation that generated code runs on.

A PDO connection pool with read/write splitting for PHP — round-robin load balancing across replicas, and automatic health checks. Create typed connections for MySQL, PostgreSQL, or SQLite via `ConnectionFactory`, then compose them into a `ConnectionPool` for replica-aware query routing. Health checks run `SELECT 1` with a configurable TTL cache so failover is fast and non-intrusive.

---

## Features

- **Read/Write Splitting** — Route writes to the primary and reads to replicas automatically
- **Transaction-Sticky Reads** — Opt-in flag routes `getReader()` to the writer while its transaction is open, so in-transaction reads see the uncommitted state
- **Round-Robin Load Balancing** — Distributes read queries evenly across all configured readers
- **Health Checks** — `SELECT 1` validation with positive and negative result caching
- **Transaction Support** — `beginTransaction()`, `commit()`, `rollback()`, `inTransaction()` on every connection
- **Reconnect** — `reconnect()` rebuilds the PDO connection from config on failure
- **MySQL / PostgreSQL / SQLite** — Dedicated factory methods per driver with typed config
- **External PDO Wrapping** — `fromPdo()` integrates existing connections without refactoring
- **ConnectionPool Stats** — `getStats()` exposes reads, writes, and failover counts at runtime

---

## Installation

```bash
composer require jardisadapter/dbconnection
```

## Quick Start

```php
use JardisAdapter\DbConnection\Factory\ConnectionFactory;

$factory = new ConnectionFactory();

// Create a MySQL connection and run a query
$connection = $factory->mysql(
    host: 'localhost',
    user: 'app_user',
    password: 'secret',
    database: 'mydb'
);

$pdo = $connection->pdo();
$users = $pdo->query('SELECT * FROM users')->fetchAll();
```

## Advanced Usage

```php
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;

$factory = new ConnectionFactory();

// Primary writer + two read replicas
$pool = new ConnectionPool(
    writer: $factory->mysql('primary.db', 'user', 'secret', 'mydb'),
    readers: [
        $factory->mysql('replica1.db', 'user', 'secret', 'mydb'),
        $factory->mysql('replica2.db', 'user', 'secret', 'mydb'),
    ],
    config: new ConnectionPoolConfig(validateConnections: true)
);

// Writes go to the primary
$pool->getWriter()->pdo()->exec('INSERT INTO orders (total) VALUES (99.99)');

// Reads are distributed round-robin across replicas
$orders = $pool->getReader()->pdo()->query('SELECT * FROM orders')->fetchAll();

// Inspect pool activity
$stats = $pool->getStats();
// ['reads' => 1, 'writes' => 1, 'failovers' => 0, 'readers' => 2]

// Transactions on a dedicated connection
$conn = $factory->postgres('localhost', 'user', 'secret', 'mydb');
$conn->beginTransaction();
try {
    $conn->pdo()->exec('UPDATE accounts SET balance = balance - 100 WHERE id = 1');
    $conn->pdo()->exec('UPDATE accounts SET balance = balance + 100 WHERE id = 2');
    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollback();
    throw $e;
}

// Wrap an existing PDO from a legacy system
$legacy = $factory->fromPdo($existingPdo);
```

### Transaction-sticky reads

While the writer has an open transaction, `getReader()` can return the writer instead of an
independent reader — so reads inside a transaction see the same uncommitted state the
transaction is writing, instead of stale data from a replica. This matters for logic that reads
and writes within one transaction boundary (e.g. a rule check that must decide on the state the
transaction itself just changed):

```php
$pool = new ConnectionPool(
    writer: $factory->mysql('primary.db', 'user', 'secret', 'mydb'),
    readers: [
        $factory->mysql('replica1.db', 'user', 'secret', 'mydb'),
    ],
    config: new ConnectionPoolConfig(stickyWriterDuringTransaction: true)
);

$pool->getWriter()->beginTransaction();
$pool->getWriter()->pdo()->exec('UPDATE accounts SET balance = balance - 100 WHERE id = 1');

// With the flag on and the writer's transaction still open, getReader() returns the writer —
// this SELECT sees the uncommitted balance change above, not the replica's stale value.
$balance = $pool->getReader()->pdo()->query('SELECT balance FROM accounts WHERE id = 1')->fetch();

$pool->getWriter()->commit();
// Transaction closed — getReader() is back to normal replica routing.
```

The flag is opt-in and defaults to `false`: without it, `getReader()` behaves exactly as before,
transaction or not. When the sticky path is active, the writer goes through the same health
check as `getWriter()` — an unhealthy writer during an open transaction throws the same
`RuntimeException` rather than silently falling back to a reader and breaking the transaction's
consistency.

`getReaders()` and `getReaderCount()` are unaffected by the flag — they report the pool's
configured reader **topology** (how many replicas exist, which ones), not the routing decision
of an individual `getReader()` call.

**Two honest limits:**
- If a consumer caches a connection once per instance (e.g. a repository that resolves its
  reader on first use and reuses it) rather than calling `getReader()` on every read, it must
  fetch the connection *inside* the transaction for the sticky binding to take effect.
- The Jardis kernel bootstrap does not yet pass a `ConnectionPoolConfig` through when building
  the pool — until that wiring is updated, the flag has no effect on the generated
  application's default bootstrap path.

## PDO Connection Options

All driver factory methods accept an `options` array passed directly to the PDO constructor. This is useful for long-running processes (RoadRunner, Swoole, FrankenPHP) where persistent connections avoid reconnect overhead per request:

```php
use PDO;

$connection = $factory->mysql(
    host: 'localhost',
    user: 'app_user',
    password: 'secret',
    database: 'mydb',
    options: [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_TIMEOUT => 5,
    ]
);
```

> **Note:** Persistent connections are reused across requests within the same worker process. Ensure your database server is configured for the expected number of concurrent connections (workers × connections per worker).

These options apply per connection — in a `ConnectionPool`, each connection can have its own settings.

## Documentation

Full documentation, guides, and API reference:

**[docs.jardis.io/en/adapter/dbconnection](https://docs.jardis.io/en/adapter/dbconnection)**

## License

This package is licensed under the [MIT License](LICENSE.md).

---

**[Jardis](https://jardis.io)** · [Documentation](https://docs.jardis.io) · [Headgent](https://headgent.com)

<!-- BEGIN jardis/dev-skills README block — do not edit by hand -->
## AI-Assisted Development

This package ships with a skill for Claude Code, Cursor, Continue, and Aider. Install it in your consuming project:

```bash
composer require --dev jardis/dev-skills
```

More details: <https://docs.jardis.io/en/skills>
<!-- END jardis/dev-skills README block -->
