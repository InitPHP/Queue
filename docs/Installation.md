# Installation

## Requirements

| | |
| --- | --- |
| PHP | **8.2** or newer |
| Core dependency | [`babelqueue/php-sdk`](https://packagist.org/packages/babelqueue/php-sdk) `^1.0` (installed automatically) |
| `ext-json` | required (bundled with PHP) |
| `ext-pcntl` | optional — enables graceful `SIGINT`/`SIGTERM` shutdown |

Plus the client for the broker you choose (see below).

## Install

```bash
composer require initphp/queue
```

Then add the client for your transport:

```bash
# Database (PDO) — nothing extra beyond ext-pdo
# Redis 6.2+
composer require predis/predis
# RabbitMQ
composer require php-amqplib/php-amqplib
```

| Transport | Needs | Notes |
| --------- | ----- | ----- |
| [PDO (Database)](Transport-PDO.md) | `ext-pdo` | Works on MySQL and SQLite. No broker process to run. |
| [Redis](Transport-Redis.md) | `predis/predis` | Redis **6.2+** (uses `BLMOVE`). Pure-PHP client, no extension needed. |
| [RabbitMQ](Transport-RabbitMQ.md) | `php-amqplib/php-amqplib` | Also needs `ext-sockets` (a dependency of php-amqplib). |

## Verify the install

```php
require 'vendor/autoload.php';

$transport = new InitPHP\Queue\Transport\Pdo\PdoTransport(new PDO('sqlite::memory:'));
$transport->createSchema();

(new InitPHP\Queue\Producer\Producer($transport, 'demo'))
    ->send('urn:demo:hello', ['who' => 'world']);

echo "ok\n";
```

## The worker CLI

The package ships an executable at `vendor/bin/queue`. Confirm it is available:

```bash
php vendor/bin/queue work
# prints the usage banner
```

See [The Worker](The-Worker.md#the-cli) for how to drive it with a bootstrap file.

## Next

- [Quick Start](Quick-Start.md) — produce and consume your first message.
- [Core Concepts](Core-Concepts.md) — how the pieces fit together.
