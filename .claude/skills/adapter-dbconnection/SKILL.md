---
name: adapter-dbconnection
description: PDO connection pool, read/write splitting, MySQL/Postgres/SQLite. Use for database connections or jardisadapter/dbconnection.
user-invocable: false
zone: post-active
persona: C
prerequisites: [rules-architecture, rules-patterns]
next: [support-dbquery, support-repository]
---

# DBCONNECTION_COMPONENT_SKILL
> jardisadapter/dbconnection | NS: `JardisAdapter\DbConnection` | PDO connection management | PHP 8.2+

## ARCHITECTURE
```
ConnectionFactory → DbConnectionInterface (mysql/postgres/sqlite/fromPdo)
ConnectionPool    → writer + readers[], health checks, load balancing, failover
```
Connections always injected — never created internally by consumers.

## FACTORY SIGNATURES
```php
$factory = new ConnectionFactory();
$factory->mysql(string $host, string $user, string $password, string $database, int $port = 3306, string $charset = 'utf8mb4', array $options = []);
$factory->postgres(string $host, string $user, string $password, string $database, int $port = 5432, array $options = []);
$factory->sqlite(string $path = ':memory:', array $options = []);
$factory->fromPdo(PDO $pdo, bool $manageLifecycle = false);
```

## CONNECTION METHODS
```php
$conn->pdo();              // PDO instance
$conn->isConnected();      // bool
$conn->connect();
$conn->disconnect();
$conn->reconnect();
$conn->getDatabaseName();  // string
$conn->getServerVersion(); // e.g. "8.0.32"
$conn->getDriverName();    // 'mysql'|'pgsql'|'sqlite'
$conn->inTransaction();    // bool
$conn->beginTransaction();
$conn->commit();
$conn->rollback();
```

## EXTERNAL CONNECTIONS (fromPdo)
Wraps existing PDO from frameworks or legacy systems.

| Behavior | Native drivers | `fromPdo` (manageLifecycle: false) |
|----------|---------------|-------------------------------------|
| `connect()` | idempotent reconnect | throws `RuntimeException` if disconnected |
| `disconnect()` | closes PDO | no-op |
| `reconnect()` | rebuilds via DSN | if connected: `SELECT 1` health check; if disconnected: throws `RuntimeException` |
| `isConnected()` after disconnect | `false` | `true` |
| `getDatabaseName()` | from config | auto-detected from PDO |

- `manageLifecycle: true` → `disconnect()` closes PDO, `isConnected()` → `false`
- **Pool caveat:** External connections cannot auto-recover; pool skips dead connection but cannot replace it

## CONNECTION POOL
```php
use JardisAdapter\DbConnection\{ConnectionPool};
use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;

$pool = new ConnectionPool(
    writer: $factory->mysql('primary', 'user', 'pass', 'mydb'),
    readers: [
        $factory->mysql('replica1', 'user', 'pass', 'mydb'),
        $factory->mysql('replica2', 'user', 'pass', 'mydb'),
    ],
    config: new ConnectionPoolConfig(
        loadBalancingStrategy: ConnectionPoolConfig::STRATEGY_ROUND_ROBIN,
        validateConnections: true,
        healthCheckCacheTtl: 30,
        healthCheckNegativeCacheTtl: 0,
    )
);

// Without replication (writer used for reads)
$pool = new ConnectionPool(writer: $factory->mysql('localhost', 'user', 'pass', 'mydb'));

$pool->getWriter();       // INSERT/UPDATE/DELETE
$pool->getReader();       // SELECT (load-balanced)
$pool->getReaders();      // all reader connections
$pool->getReaderCount();  // int
$pool->getStats();        // ['reads', 'writes', 'failovers', 'readers']
$pool->resetStats();
```

Load balancing strategies: `STRATEGY_ROUND_ROBIN` (default), `STRATEGY_RANDOM`.

## INTERFACES (JardisSupport\Contract\DbConnection)
| Interface | Key methods |
|-----------|-------------|
| `ConnectionInterface` | `connect()`, `disconnect()`, `isConnected()` |
| `DbConnectionInterface extends ConnectionInterface` | `pdo()`, `reconnect()`, transactions, metadata |
| `DatabaseConfigInterface` | `getDsn()`, `getUser()`, `getPassword()`, `getOptions()`, `getDatabaseName()`, `getDriverName()` |
| `ConnectionPoolInterface` | `getReader/getWriter/getReaders/getReaderCount/getStats/resetStats` |

## EXCEPTIONS
- `RuntimeException` — connection errors, external reconnect failures
- `InvalidArgumentException` — configuration errors

## LAYER
- **Infrastructure:** create connections via `ConnectionFactory`
- **Application:** inject `DbConnectionInterface` or `ConnectionPoolInterface`
- **Domain:** NEVER imports connection classes
