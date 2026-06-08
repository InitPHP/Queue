<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Transport;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Exceptions\ConfigurationException;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Transport\Pdo\PdoTransport;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoTransportTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function transport(int $retryAfter = 90): PdoTransport
    {
        $transport = new PdoTransport($this->pdo, 'jobs', null, 'default', $retryAfter);
        $transport->createSchema();

        return $transport;
    }

    private function envelope(string $urn = 'urn:babel:orders:created'): string
    {
        return EnvelopeCodec::encode(EnvelopeCodec::make($urn, ['order_id' => 1], 'orders'));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    public function test_create_schema_is_idempotent(): void
    {
        $transport = $this->transport();
        $transport->createSchema(); // second call must not throw

        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('jobs', $tables);
        self::assertContains('jobs_failed', $tables);
    }

    public function test_publish_inserts_a_row_and_returns_an_id(): void
    {
        $transport = $this->transport();
        $id = $transport->publish($this->envelope(), 'orders');

        self::assertIsString($id);
        self::assertSame(1, $this->rowCount('jobs'));

        $row = $this->pdo->query('SELECT queue, urn, attempts FROM jobs')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('orders', $row['queue']);
        self::assertSame('urn:babel:orders:created', $row['urn']);
        self::assertSame(0, (int) $row['attempts']);
    }

    public function test_reserve_claims_a_row_and_hides_it_from_a_second_reserve(): void
    {
        $transport = $this->transport();
        $transport->publish($this->envelope(), 'orders');

        $message = $transport->reserve('orders');
        self::assertInstanceOf(ReceivedMessage::class, $message);
        self::assertSame('urn:babel:orders:created', $message->getUrn());

        $reservedAt = $this->pdo->query('SELECT reserved_at FROM jobs')->fetchColumn();
        self::assertNotNull($reservedAt);

        self::assertNull($transport->reserve('orders'), 'a reserved row is not handed out again');
    }

    public function test_reserve_returns_null_on_empty_queue(): void
    {
        self::assertNull($this->transport()->reserve('orders'));
    }

    public function test_ack_deletes_the_row(): void
    {
        $transport = $this->transport();
        $transport->publish($this->envelope(), 'orders');
        $message = $transport->reserve('orders');
        self::assertNotNull($message);

        $transport->ack($message);

        self::assertSame(0, $this->rowCount('jobs'));
    }

    public function test_release_requeues_with_updated_attempts(): void
    {
        $transport = $this->transport();
        $transport->publish($this->envelope(), 'orders');
        $message = $transport->reserve('orders');
        self::assertNotNull($message);

        $envelope = $message->envelope();
        $envelope['attempts'] = 2;
        $transport->release($message, EnvelopeCodec::encode($envelope), 0);

        $row = $this->pdo->query('SELECT attempts, reserved_at FROM jobs')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2, (int) $row['attempts']);
        self::assertNull($row['reserved_at'], 'released row is reservable again');

        $again = $transport->reserve('orders');
        self::assertNotNull($again);
        self::assertSame(2, $again->attempts());
    }

    public function test_release_with_delay_hides_the_row_until_available(): void
    {
        $transport = $this->transport();
        $transport->publish($this->envelope(), 'orders');
        $message = $transport->reserve('orders');
        self::assertNotNull($message);

        $transport->release($message, $message->rawBody(), 3600);

        self::assertNull($transport->reserve('orders'), 'delayed row is not yet available');
    }

    public function test_dead_letter_moves_the_row_to_the_failed_table(): void
    {
        $transport = $this->transport();
        $transport->publish($this->envelope(), 'orders');
        $message = $transport->reserve('orders');
        self::assertNotNull($message);

        $annotated = $message->envelope();
        $annotated['attempts'] = 3;
        $annotated['dead_letter'] = ['reason' => 'failed'];
        $transport->deadLetter($message, EnvelopeCodec::encode($annotated));

        self::assertSame(0, $this->rowCount('jobs'));
        self::assertSame(1, $this->rowCount('jobs_failed'));

        $row = $this->pdo->query('SELECT urn, attempts, reason FROM jobs_failed')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('urn:babel:orders:created', $row['urn']);
        self::assertSame(3, (int) $row['attempts']);
        self::assertSame('failed', $row['reason']);
    }

    public function test_a_stale_reservation_is_reclaimed(): void
    {
        $transport = $this->transport(retryAfter: 0);
        $transport->publish($this->envelope(), 'orders');

        $first = $transport->reserve('orders');
        self::assertNotNull($first);

        // With retryAfter = 0 the reservation is immediately considered abandoned.
        $reclaimed = $transport->reserve('orders');
        self::assertNotNull($reclaimed);
        self::assertSame($first->receipt(), $reclaimed->receipt());
    }

    public function test_invalid_table_name_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new PdoTransport($this->pdo, 'jobs; DROP TABLE users');
    }
}
