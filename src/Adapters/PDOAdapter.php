<?php
/**
 * InitPHP Queue
 *
 * This file is part of InitPHP Queue.
 *
 * @author      Muhammet ŞAFAK <info@muhammetsafak.com.tr>
 * @copyright   Copyright © 2023 Muhammet ŞAFAK
 * @license     ./LICENSE  MIT
 * @version     1.0
 * @link        https://www.muhammetsafak.com.tr
 */

namespace InitPHP\Queue\Adapters;

use PDO;
use Throwable;
use InitPHP\Queue\Interfaces\AdapterInterface;
use InitPHP\Queue\Interfaces\JobInterface;

class PDOAdapter implements AdapterInterface
{

    private ?PDO $pdo;

    private string $table;

    public function __construct(PDO $pdo, string $table = 'queue')
    {
        $this->pdo = $pdo;
        $this->table = $table;
    }

    /**
     * @param object $message
     * @return bool
     */
    public function worker(object $message): bool
    {
        try {
            $payload = json_decode($message->payload, true);
            $jobClass = $payload['jobClass'];
            $jobObj = $jobClass($this);
            if (!($jobObj instanceof JobInterface)) {
                return false;
            }
            $jobObj->setPayload($payload['payload'])
                ->setId($message->id);

            return $jobObj->handle() ? $jobObj->ack() : $jobObj->nack();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function handle(string $channel, string $queue): bool
    {
        try {

            do {
                $stmt = $this->pdo->prepare("SELECT * FROM " . $this->table . " WHERE channel = :channel AND queue = :queue AND status = 0 LIMIT 0, 1");
                if (!$stmt) {
                    return false;
                }
                $stmt->bindValue(':channel', $channel);
                $stmt->bindValue(':queue', $queue);
                if (!$stmt->execute()) {
                    return false;
                }
                if ($stmt->rowCount() < 1) {
                    return false;
                }
                $stmt->setFetchMode(PDO::FETCH_OBJ);
                $res = $stmt->fetch();

                $update = $this->pdo->prepare("UPDATE " . $this->table . " SET status = 1, updated_at = :updated_at WHERE id = :id");
                $update->bindValue(':id', $res->id, PDO::PARAM_INT);
                $update->bindValue(':updated_at', date("Y-m-d H:i:s"));
                $update->execute();

                $this->worker($res);
            } while (true);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function push(string $channel, string $queue, JobInterface $job): bool
    {
        try {
            $insert = $this->pdo->prepare("INSERT INTO " . $this->table . " (channel, queue, payload, created_at, updated_at, status) VALUES (:channel, :queue, :payload, :created_at, NULL, 0);");

            $insert->bindValue(':channel', $channel);
            $insert->bindValue(':queue', $queue);
            $insert->bindValue(':payload', json_encode(['jobClass' => get_class($job), 'payload' => $job->getPayload()], JSON_UNESCAPED_SLASHES));
            $insert->bindValue(':created_at', date("Y-m-d H:i:s"));
            return $insert->execute();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function ack($id, ?string $message = null): bool
    {
        try {
            $delete = $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE id = :id");
            $delete->bindValue(':id', $id);
            $delete->execute();
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function nack($id, ?string $message = null): bool
    {
        try {
            $this->pdo->beginTransaction();
            $insert = $this->pdo->prepare("INSERT INTO " . $this->table . "_failed (queue_id, channel, queue, payload, created_at, updated_at, status) SELECT * FROM " . $this->table . " WHERE id = :id");
            $insert->bindValue(':id', $id);
            $insert->execute();
            $delete = $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE id = :id");
            $delete->bindValue(':id', $id);
            $delete->execute();

            return $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): bool
    {
        $this->pdo = null;

        return true;
    }

}
