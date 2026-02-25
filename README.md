# Jardis DbConnection

![Build Status](https://github.com/jardisAdapter/dbConnection/actions/workflows/ci.yml/badge.svg)
[![License](https://img.shields.io/badge/license-PolyForm%20Noncommercial-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-success.svg)](phpstan.neon)
[![PSR-4](https://img.shields.io/badge/autoload-PSR--4-blue.svg)](https://www.php-fig.org/psr/psr-4/)
[![PSR-12](https://img.shields.io/badge/code%20style-PSR--12-orange.svg)](phpcs.xml)
[![Coverage](https://img.shields.io/badge/coverage->92%25-brightgreen)](https://github.com/jardisAdapter/dbConnection)

> Part of the **[Jardis Ecosystem](https://jardis.io)** — A modular DDD framework for PHP

A production-ready PHP library for professional PDO connection management with advanced connection pooling, load balancing, and automatic failover capabilities. Built for modern PHP applications requiring high availability and optimal performance.

---

## Features

- **Smart Connection Pooling** — Automatic read/write splitting with intelligent load balancing across replicas
- **Replication Ready** — Native support for primary/replica database architectures
- **PHP-FPM Optimized** — Persistent connections for maximum performance in production
- **Auto-Failover** — Health checks with automatic reconnection and graceful degradation
- **Multiple Drivers** — MySQL/MariaDB, PostgreSQL, SQLite, and external PDO support
- **Type-Safe** — Full PHP 8.2+ type declarations with readonly classes and strict mode
- **Legacy Integration** — Wrap existing PDO connections without refactoring

---

## Installation

```bash
composer require jardisadapter/dbconnection
```

## Quick Start

```php
use JardisAdapter\DbConnection\MySql;
use JardisAdapter\DbConnection\Data\MySqlConfig;

$connection = new MySql(new MySqlConfig(
    host: 'localhost',
    user: 'app_user',
    password: 'secure_password',
    database: 'my_application'
));

$pdo = $connection->pdo();
$users = $pdo->query('SELECT * FROM users')->fetchAll();
```

## Documentation

Full documentation, examples and API reference:

**-> [jardis.io/docs/adapter/dbconnection](https://jardis.io/docs/adapter/dbconnection)**

## Jardis Ecosystem

This package is part of the Jardis Ecosystem — a collection of modular, high-quality PHP packages designed for Domain-Driven Design.

| Category    | Packages                                                                             |
|-------------|--------------------------------------------------------------------------------------|
| **Core**    | Domain, Kernel, Data, Workflow                                                       |
| **Adapter** | Cache, Logger, Messaging, DbConnection |
| **Support** | DotEnv, DbQuery, Validation, Factory, ClassVersion |
| **Tools**   | Builder, DbSchema                                                                            |

**-> [Explore all packages](https://jardis.io/docs)**

## License

This package is licensed under the [PolyForm Noncommercial License 1.0.0](LICENSE).

For commercial use, see [COMMERCIAL.md](COMMERCIAL.md).

---

**[Jardis Ecosystem](https://jardis.io)** by [Headgent Development](https://headgent.com)
