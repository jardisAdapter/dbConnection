# jardisadapter/dbconnection

PDO connection management for MySQL/MariaDB, PostgreSQL, and SQLite with `ConnectionPool` for read/write splitting, load balancing, and failover.

## Usage Essentials

- **`ConnectionFactory` is the single entry point** — `new ConnectionFactory()->mysql(...)|postgres(...)|sqlite(...)|fromPdo(...)`. Consumers never create a PDO directly.
- **Connections are injected, never built internally.** Application takes `DbConnectionInterface`/`ConnectionPoolInterface`; Domain does not know them at all.
- **`fromPdo()` default `manageLifecycle: false`** — `disconnect()` is a no-op, the external system keeps control. Set to `true` only if the library is allowed to close the PDO.
- **External connections cannot be re-connected.** `reconnect()` on a disconnected external connection throws `RuntimeException`. In the pool: a dead external connection is skipped by failover — the external system must replace it.
- **ConnectionPool without replicas:** `new ConnectionPool(writer: $conn)` is allowed — the writer is then also used for reads. Load balancing via `ConnectionPoolConfig` (`STRATEGY_ROUND_ROBIN` default, `STRATEGY_RANDOM`).
- **Error semantics:** connection errors throw `RuntimeException`, config errors throw `InvalidArgumentException`.

## Full Reference

https://docs.jardis.io/en/adapter/dbconnection
