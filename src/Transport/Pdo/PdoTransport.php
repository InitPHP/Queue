<?php

declare(strict_types=1);

namespace InitPHP\Queue\Transport\Pdo;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\Transport;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Exceptions\ConfigurationException;
use InitPHP\Queue\Message\AbandonedDelivery;
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
 * becomes reservable again, so a crashed worker never loses a message. Taking
 * over such a lapsed reservation counts as one failed attempt: `attempts` (and the
 * envelope in `payload`) are raised by one in the same `UPDATE`, so a handler that
 * kills its process every time still reaches `maxAttempts` and the dead-letter
 * table. A message put back with `release()` has its reservation cleared and is
 * not counted, so waiting for another handler's in-flight key costs no attempt.
 *
 * `release()` is conditional on the reservation this transport instance took. A
 * worker whose reservation lapsed and was taken over cannot overwrite the new
 * holder's reservation, but a retry it reports still counts: the new `attempts`
 * and payload are recorded without touching the reservation, so `maxAttempts` is
 * reached whoever holds the row. A verbatim release (a message put back
 * unchanged) never touches `payload` or `attempts` and never shortens a back-off.
 * The stamp has one-second resolution, so with `$retryAfter` of 0 two
 * reservations inside the same second look alike.
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

    /** @var array<int, string> row id => the `reserved_at` stamp this instance wrote at reserve() */
    private array $reservations = [];

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
            "SELECT id, payload, reserved_at FROM {$this->table}"
            . ' WHERE queue = :queue AND available_at <= :now AND (reserved_at IS NULL OR reserved_at <= :stale)'
            . ' ORDER BY id ASC LIMIT 1',
        );

        // Re-select on a lost claim: another worker took this row, so try the next.
        for ($attempt = 0; $attempt < 16; ++$attempt) {
            $select->execute([':queue' => $queue, ':now' => $now, ':stale' => $stale]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            $id = (int) $row['id'];
            $payload = (string) $row['payload'];
            $previous = $row['reserved_at'] === null ? null : (string) $row['reserved_at'];

            if ($previous === null) {
                $abandoned = false;
                $claimed = $this->claimFree($id, $now);
            } else {
                // The row is still stamped as reserved but the reservation lapsed: the
                // worker that held it never acked, released or dead-lettered it (it
                // died, or its handler outlived `retryAfter`). That delivery counts as
                // an attempt, recorded in the same UPDATE that takes the row so two
                // workers cannot both count it. A message put back with release()
                // clears the stamp, so waiting on an in-flight key never lands here.
                $counted = AbandonedDelivery::countedBody($payload);
                $abandoned = $counted !== null;
                $claimed = $this->claimLapsed($id, $now, $previous, $counted);
                $payload = $counted ?? $payload;
            }

            if ($claimed) {
                $this->reservations[$id] = $now;

                return new ReceivedMessage($queue, $payload, EnvelopeCodec::decode($payload), $id, $abandoned);
            }
        }

        return null;
    }

    private function claimFree(int $id, string $now): bool
    {
        $claim = $this->pdo->prepare(
            "UPDATE {$this->table} SET reserved_at = :reserved WHERE id = :id AND reserved_at IS NULL",
        );
        $claim->execute([':reserved' => $now, ':id' => $id]);

        return $claim->rowCount() === 1;
    }

    /**
     * Take a row whose reservation lapsed. The `WHERE` matches the exact stamp that
     * was read, so only one of several workers racing for the row succeeds.
     */
    private function claimLapsed(int $id, string $now, string $previous, ?string $payload): bool
    {
        $assign = $payload !== null ? ', attempts = :attempts, payload = :payload' : '';
        $params = [':reserved' => $now, ':id' => $id, ':previous' => $previous];
        if ($payload !== null) {
            $params[':attempts'] = $this->attemptsOf(EnvelopeCodec::decode($payload));
            $params[':payload'] = $payload;
        }

        $claim = $this->pdo->prepare(
            "UPDATE {$this->table} SET reserved_at = :reserved{$assign} WHERE id = :id AND reserved_at = :previous",
        );
        $claim->execute($params);

        return $claim->rowCount() === 1;
    }

    public function ack(ReceivedMessage $message): void
    {
        unset($this->reservations[(int) $message->receipt()]);

        $this->pdo
            ->prepare("DELETE FROM {$this->table} WHERE id = :id")
            ->execute([':id' => (int) $message->receipt()]);
    }

    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
    {
        $id = (int) $message->receipt();
        $stamp = $this->reservations[$id] ?? null;
        unset($this->reservations[$id]);

        $availableAt = $this->at(max(0, $delaySeconds));

        if ($payload === $message->rawBody()) {
            $this->putBackUnchanged($id, $stamp, $availableAt);

            return;
        }

        $attempts = $this->attemptsOf(EnvelopeCodec::decode($payload));

        if ($stamp === null) {
            // A message this instance did not reserve: no stamp to compare.
            $this->pdo->prepare(
                "UPDATE {$this->table} SET payload = :payload, attempts = :attempts, available_at = :available_at, reserved_at = NULL WHERE id = :id",
            )->execute([':payload' => $payload, ':attempts' => $attempts, ':available_at' => $availableAt, ':id' => $id]);

            return;
        }

        // A retry. Applies in full while the reservation is still ours, or the row
        // has been released since. `attempts` only ever moves forward, so two
        // workers recording the same failure cannot lower it. (payload comes first
        // because MySQL evaluates the assignments left to right.)
        $owned = $this->pdo->prepare(
            "UPDATE {$this->table} SET"
            . ' payload = CASE WHEN attempts > :attempts_a THEN payload ELSE :payload END,'
            . ' attempts = CASE WHEN attempts > :attempts_b THEN attempts ELSE :attempts_c END,'
            . ' available_at = :available_at, reserved_at = NULL'
            . ' WHERE id = :id AND (reserved_at = :stamp OR reserved_at IS NULL)',
        );
        $owned->execute([
            ':attempts_a' => $attempts,
            ':attempts_b' => $attempts,
            ':attempts_c' => $attempts,
            ':payload' => $payload,
            ':available_at' => $availableAt,
            ':id' => $id,
            ':stamp' => $stamp,
        ]);

        if ($owned->rowCount() > 0) {
            return;
        }

        // Another worker holds the row now (our reservation lapsed and was taken
        // over). The failure still counts: record the attempt and its back-off
        // without touching the holder's reservation, so a handler that always runs
        // longer than `retryAfter` still reaches `maxAttempts` and the dead-letter
        // table, as it did before. Taking the row over already counted one attempt
        // (see reserve()), so the stored count may equal ours: it then stays, and
        // only the back-off is applied. A delay is only ever extended, never
        // shortened, and `attempts` never goes back.
        $this->pdo->prepare(
            "UPDATE {$this->table} SET"
            . ' payload = CASE WHEN attempts < :attempts_a THEN :payload ELSE payload END,'
            . ' attempts = CASE WHEN attempts < :attempts_b THEN :attempts_c ELSE attempts END,'
            . ' available_at = CASE WHEN available_at > :available_a THEN available_at ELSE :available_b END'
            . ' WHERE id = :id AND attempts <= :attempts_guard',
        )->execute([
            ':attempts_a' => $attempts,
            ':attempts_b' => $attempts,
            ':attempts_c' => $attempts,
            ':payload' => $payload,
            ':available_a' => $availableAt,
            ':available_b' => $availableAt,
            ':id' => $id,
            ':attempts_guard' => $attempts,
        ]);
    }

    /**
     * Put a message back unchanged: only reschedule it, and only while the
     * reservation is still ours, so it can neither undo a retry another worker has
     * recorded nor shorten its back-off.
     */
    private function putBackUnchanged(int $id, ?string $stamp, string $availableAt): void
    {
        $guard = $stamp !== null ? ' AND reserved_at = :stamp' : '';
        $params = [':available_a' => $availableAt, ':available_b' => $availableAt, ':id' => $id];
        if ($stamp !== null) {
            $params[':stamp'] = $stamp;
        }

        $this->pdo->prepare(
            "UPDATE {$this->table} SET"
            . ' available_at = CASE WHEN available_at > :available_a THEN available_at ELSE :available_b END,'
            . " reserved_at = NULL WHERE id = :id{$guard}",
        )->execute($params);
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        unset($this->reservations[(int) $message->receipt()]);

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
     * deployments usually own their migrations — see docs/Transport-PDO.md.
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
