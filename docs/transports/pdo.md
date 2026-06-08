# PDO (database) transport

`InitPHP\Queue\Transport\Pdo\PdoTransport` is a database-backed transport — the
one the core SDK does not ship. It needs no broker process: jobs live in two
tables. It is the simplest way to add a durable queue to an app that already has a
database, and it works on **MySQL** and **SQLite**.

```php
use InitPHP\Queue\Transport\Pdo\PdoTransport;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app', 'user', 'pass');

$transport = new PdoTransport(
    pdo:          $pdo,
    table:        'jobs',     // main queue table
    failedTable:  null,       // defaults to "<table>_failed"
    defaultQueue: 'default',
    retryAfter:   90,         // seconds before a stuck reservation is reclaimed
);
```

PHP 8 puts PDO in exception error mode by default; keep it that way so failures
surface instead of being swallowed.

## Tables

For development and tests, let the transport create the tables:

```php
$transport->createSchema();   // CREATE TABLE IF NOT EXISTS … (idempotent)
```

In production you usually own your migrations. The DDL below is what
`createSchema()` produces on MySQL — create it through your migration tool:

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    urn VARCHAR(255) NOT NULL,
    trace_id VARCHAR(64) NULL,
    attempts INT NOT NULL DEFAULT 0,
    payload TEXT NOT NULL,
    available_at DATETIME NOT NULL,
    reserved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY jobs_reserve_idx (queue, available_at, reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs_failed (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    urn VARCHAR(255) NOT NULL,
    trace_id VARCHAR(64) NULL,
    attempts INT NOT NULL DEFAULT 0,
    payload TEXT NOT NULL,
    reason VARCHAR(64) NOT NULL,
    failed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Columns

The full envelope is stored as JSON in `payload`; `queue`, `urn`, `trace_id` and
`attempts` are denormalised into columns only for indexing and inspection.

| Column | Purpose |
| ------ | ------- |
| `queue` | The logical queue; reservation filters on it. |
| `urn` | The message URN, for inspection. |
| `trace_id` | The correlation id, for inspection. |
| `attempts` | Mirror of the envelope's `attempts`. |
| `payload` | The canonical envelope JSON (the source of truth). |
| `available_at` | When the row becomes reservable (now, or later for a delayed retry). |
| `reserved_at` | When a worker reserved it; `NULL` when ready. |
| `created_at` | Insert time. |

> The table name is validated as a bare SQL identifier (letters, digits,
> underscores) to keep it safe to interpolate; anything else raises a
> `ConfigurationException`.

## How reservation works

`reserve()` uses a **portable optimistic claim** that is correct on MySQL and
SQLite alike, without `SELECT … FOR UPDATE SKIP LOCKED`:

1. `SELECT` the oldest row for the queue that is ready
   (`available_at <= now` and not currently reserved).
2. `UPDATE … SET reserved_at = now WHERE id = ? AND (reserved_at IS NULL OR reserved_at <= :stale)`.
3. If exactly one row was affected, the claim succeeded; otherwise another worker
   won it, so try the next row.

This guarantees two workers never run the same job.

### Visibility timeout (`retryAfter`)

A row reserved by a worker that then crashes would otherwise be stuck forever. Any
reservation older than `retryAfter` seconds (default 90) is treated as abandoned
and becomes reservable again — so no message is lost. Set it comfortably above
your slowest handler's runtime.

## Lifecycle, in SQL terms

| Operation | Effect |
| --------- | ------ |
| `publish()` | `INSERT` a ready row; returns the new row id. |
| `reserve()` | The optimistic claim above. |
| `ack()` | `DELETE` the row. |
| `release()` | `UPDATE` the row with the new payload/attempts and `available_at = now + delay`, clearing `reserved_at`. |
| `deadLetter()` | In one transaction: `INSERT` into the failed table, then `DELETE` the row. |

## Operational notes

- **Polling.** PDO reservation is a poll: when the queue is empty, `reserve()`
  returns immediately and the worker sleeps for `WorkerOptions::$sleepWhenEmpty`.
  Tune that for your latency/load trade-off.
- **Indexing.** The `(queue, available_at, reserved_at)` index keeps reservation
  fast as the table grows; keep it.
- **Housekeeping.** `ack()` deletes rows, so the main table stays small. Prune the
  `*_failed` table on your own schedule once messages are reviewed or replayed.

See [Dead-letter handling](../dead-letter.md) for inspecting and replaying failed
rows.
