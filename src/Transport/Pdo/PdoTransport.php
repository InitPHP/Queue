<?php

declare(strict_types=1);

namespace InitPHP\Queue\Transport\Pdo;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\Transport;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Exceptions\ConfigurationException;
use InitPHP\Queue\Message\ReceivedMessage;
use PDO;

/**
 * A database-backed transport — the differentiator the core SDK does not ship
 * (it provides Redis and AMQP only). It both publishes and consumes the
 * canonical envelope through two tables: a main `jobs` table and a `jobs_failed`
 * dead-letter table.
 *
 * Reservation is a portable optimistic claim — `SELECT` the oldest ready row,
 * then a conditional `UPDATE … WHERE reserved_at IS NULL` whose affected-row
 * count confirms the claim — so two workers never run the same job, and it works
 * identically on MySQL and SQLite without `SELECT … FOR UPDATE SKIP LOCKED`. A
 * reservation older than `$retryAfter` seconds is treated as abandoned and
 * becomes reservable again, so a crashed worker never loses a message.
 *
 * The whole envelope is stored as JSON in `payload`; `queue`, `urn`, `trace_id`
 * and `attempts` are denormalised into columns purely for indexing and
 * inspection.
 *
 * Requires `ext-pdo`.
 */
final class PdoTransport implements Transport, ConsumerTransport
{
    private readonly string $failedTable;

    /**
     * @param  PDO  $pdo  A connection in exception error mode (PHP 8's default).
     * @param  string  $table  Main queue table name.
     * @param  string|null  $failedTable  Dead-letter table name; defaults to `<table>_failed`.
     * @param  string  $defaultQueue  Queue used when publish() is given none.
     * @param  int  $retryAfter  Seconds after which a reserved-but-unfinished row is reclaimed.
     *
     * @throws ConfigurationException When a table name is not a bare SQL identifier.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'jobs',
        ?string $failedTable = null,
        private readonly string $defaultQueue = 'default',
        private readonly int $retryAfter = 90,
    ) {
        $this->failedTable = $failedTable ?? $table . '_failed';

        $this->assertIdentifier($this->table);
        $this->assertIdentifier($this->failedTable);
    }

    public function publish(string $payload, ?string $queue = null): ?string
    {
        $target = $queue ?? $this->defaultQueue;
        $envelope = EnvelopeCodec::decode($payload);
        $now = $this->now();

        $statement = $this->pdo->prepare(
            "INSERT INTO {$this->table} (queue, urn, trace_id, attempts, payload, available_at, reserved_at, created_at)"
            . ' VALUES (:queue, :urn, :trace_id, :attempts, :payload, :available_at, NULL, :created_at)',
        );
        $statement->execute([
            ':queue' => $target,
            ':urn' => EnvelopeCodec::urn($envelope),
            ':trace_id' => $this->stringOrNull($envelope['trace_id'] ?? null),
            ':attempts' => $this->attemptsOf($envelope),
            ':payload' => $payload,
            ':available_at' => $now,
            ':created_at' => $now,
        ]);

        $id = $this->pdo->lastInsertId();

        return $id === false || $id === '0' ? null : $id;
    }

    public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
    {
        $now = $this->now();
        $stale = $this->at(-$this->retryAfter);

        $select = $this->pdo->prepare(
            "SELECT id, payload FROM {$this->table}"
            . ' WHERE queue = :queue AND available_at <= :now AND (reserved_at IS NULL OR reserved_at <= :stale)'
            . ' ORDER BY id ASC LIMIT 1',
        );
        $claim = $this->pdo->prepare(
            "UPDATE {$this->table} SET reserved_at = :reserved WHERE id = :id AND (reserved_at IS NULL OR reserved_at <= :stale)",
        );

        // Re-select on a lost claim: another worker took this row, so try the next.
        for ($attempt = 0; $attempt < 16; ++$attempt) {
            $select->execute([':queue' => $queue, ':now' => $now, ':stale' => $stale]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            $claim->execute([':reserved' => $now, ':id' => $row['id'], ':stale' => $stale]);
            if ($claim->rowCount() === 1) {
                $payload = (string) $row['payload'];

                return new ReceivedMessage($queue, $payload, EnvelopeCodec::decode($payload), (int) $row['id']);
            }
        }

        return null;
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->pdo
            ->prepare("DELETE FROM {$this->table} WHERE id = :id")
            ->execute([':id' => (int) $message->receipt()]);
    }

    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
    {
        $envelope = EnvelopeCodec::decode($payload);

        $this->pdo->prepare(
            "UPDATE {$this->table} SET payload = :payload, attempts = :attempts, available_at = :available_at, reserved_at = NULL WHERE id = :id",
        )->execute([
            ':payload' => $payload,
            ':attempts' => $this->attemptsOf($envelope),
            ':available_at' => $this->at(max(0, $delaySeconds)),
            ':id' => (int) $message->receipt(),
        ]);
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        $envelope = EnvelopeCodec::decode($payload);
        $deadLetter = is_array($envelope['dead_letter'] ?? null) ? $envelope['dead_letter'] : [];
        $now = $this->now();

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                "INSERT INTO {$this->failedTable} (queue, urn, trace_id, attempts, payload, reason, failed_at, created_at)"
                . ' VALUES (:queue, :urn, :trace_id, :attempts, :payload, :reason, :failed_at, :created_at)',
            )->execute([
                ':queue' => $message->queue(),
                ':urn' => EnvelopeCodec::urn($envelope),
                ':trace_id' => $this->stringOrNull($envelope['trace_id'] ?? null),
                ':attempts' => $this->attemptsOf($envelope),
                ':payload' => $payload,
                ':reason' => $this->stringOrNull($deadLetter['reason'] ?? null) ?? 'failed',
                ':failed_at' => $now,
                ':created_at' => $now,
            ]);

            $this->pdo
                ->prepare("DELETE FROM {$this->table} WHERE id = :id")
                ->execute([':id' => (int) $message->receipt()]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Create the main and dead-letter tables (and the reservation index) if they
     * do not exist. A convenience for getting started and for tests; production
     * deployments usually own their migrations — see docs/transports/pdo.md.
     */
    public function createSchema(): void
    {
        $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $autoIncrement = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $suffix = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // SQLite cannot declare an index inside CREATE TABLE and needs a separate
        // statement; MySQL has no `CREATE INDEX IF NOT EXISTS`, so the index is
        // declared inline in the table instead.
        $inlineIndex = $isSqlite ? '' : ",\n    KEY {$this->table}_reserve_idx (queue, available_at, reserved_at)";

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (\n"
            . "    id {$autoIncrement},\n"
            . "    queue VARCHAR(255) NOT NULL,\n"
            . "    urn VARCHAR(255) NOT NULL,\n"
            . "    trace_id VARCHAR(64) NULL,\n"
            . "    attempts INT NOT NULL DEFAULT 0,\n"
            . "    payload TEXT NOT NULL,\n"
            . "    available_at DATETIME NOT NULL,\n"
            . "    reserved_at DATETIME NULL,\n"
            . "    created_at DATETIME NOT NULL{$inlineIndex}\n"
            . "){$suffix}",
        );

        if ($isSqlite) {
            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS {$this->table}_reserve_idx ON {$this->table} (queue, available_at, reserved_at)",
            );
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->failedTable} (\n"
            . "    id {$autoIncrement},\n"
            . "    queue VARCHAR(255) NOT NULL,\n"
            . "    urn VARCHAR(255) NOT NULL,\n"
            . "    trace_id VARCHAR(64) NULL,\n"
            . "    attempts INT NOT NULL DEFAULT 0,\n"
            . "    payload TEXT NOT NULL,\n"
            . "    reason VARCHAR(64) NOT NULL,\n"
            . "    failed_at DATETIME NOT NULL,\n"
            . "    created_at DATETIME NOT NULL\n"
            . "){$suffix}",
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function attemptsOf(array $envelope): int
    {
        return is_int($envelope['attempts'] ?? null) ? $envelope['attempts'] : 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function at(int $offsetSeconds): string
    {
        return date('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    private function assertIdentifier(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new ConfigurationException(sprintf(
                'Table name "%s" is not a valid SQL identifier (letters, digits and underscores only).',
                $name,
            ));
        }
    }
}
