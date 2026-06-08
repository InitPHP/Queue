<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Integration;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Transport\Pdo\PdoTransport;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see PdoTransport} against a real MySQL server — the one path the
 * SQLite unit test cannot cover (MySQL DDL, AUTO_INCREMENT, the inline reserve
 * index). Skipped unless QUEUE_TEST_MYSQL_DSN is set (the CI integration job
 * provides it via a MySQL service container); locally it is a no-op.
 */
#[Group('integration')]
final class PdoMysqlIntegrationTest extends TestCase
{
    private const TABLE = 'it_jobs';

    private PDO $pdo;

    private PdoTransport $transport;

    protected function setUp(): void
    {
        $dsn = getenv('QUEUE_TEST_MYSQL_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set QUEUE_TEST_MYSQL_DSN to run the MySQL integration test.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('QUEUE_TEST_MYSQL_USER') ?: 'root',
            getenv('QUEUE_TEST_MYSQL_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE . '_failed');

        $this->transport = new PdoTransport($this->pdo, self::TABLE);
        $this->transport->createSchema();
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    public function test_full_lifecycle_against_mysql(): void
    {
        $payload = EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 5], 'orders'));

        $id = $this->transport->publish($payload, 'orders');
        self::assertIsString($id);
        self::assertSame(1, $this->rowCount(self::TABLE));

        $message = $this->transport->reserve('orders');
        self::assertNotNull($message);
        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertNull($this->transport->reserve('orders'), 'a reserved row is hidden');

        $annotated = $message->envelope();
        $annotated['attempts'] = 3;
        $annotated['dead_letter'] = ['reason' => 'failed'];
        $this->transport->deadLetter($message, EnvelopeCodec::encode($annotated));

        self::assertSame(0, $this->rowCount(self::TABLE));
        self::assertSame(1, $this->rowCount(self::TABLE . '_failed'));
    }
}
