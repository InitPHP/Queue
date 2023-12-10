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

declare(strict_types=1);
namespace InitPHP\Queue\Adapters;

use Throwable;
use InitPHP\Queue\Exceptions\JobException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use InitPHP\Queue\Interfaces\AdapterInterface;
use InitPHP\Queue\Interfaces\JobInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMQAdapter implements AdapterInterface
{

    private AMQPStreamConnection $connection;

    /**
     * @var AMQPChannel[]
     */
    private array $channel = [];

    /**
     * @var AMQPChannel[]
     */
    private array $failed = [];

    /**
     * @throws Throwable
     */
    public function __construct(string $host, int $port, string $username, string $password)
    {
        $this->connection = new AMQPStreamConnection($host, $port, $username, $password);
    }

    private function declareChannel(string $channel, string $queue): string
    {
        $queue = $channel . '_' . $queue;
        if (isset($this->channel[$queue])) {
            return $queue;
        }
        $this->channel[$queue] = $this->connection->channel();
        $this->channel[$queue]->queue_declare($queue, false, true, false, false, false, null, null);
        $this->failed[$queue . '_failed'] = $this->connection->channel();
        $this->failed[$queue . '_failed']->queue_declare($queue. '_failed', false, true, false, false, false, null, null);

        return $queue;
    }

    /**
     * @inheritDoc
     */
    public function worker(object $message): bool
    {
        $payload = json_decode($message->body, true);

        try {
            $job = isset($payload['jobClass']) ? new $payload['jobClass']($this) : null;
            if (!($job instanceof JobInterface)) {
                throw new JobException();
            }
            $job->setPayload($payload['payload'])
                ->setId($message->delivery_info);
            $job->handle() ? $job->ack() : $job->nack();
            return true;
        } catch (Throwable $e) {
            if (isset($job) && ($job instanceof JobInterface) && !empty($job->getId())) {
                $job->getId()['channel']->basic_nack($job->getId()['delivery_tag']);
            }
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function handle(string $channel, string $queue): bool
    {
        try {
            $queue = $this->declareChannel($channel, $queue);
            $this->channel[$queue]->basic_qos(0, 1, null);
            $this->channel[$queue]->basic_consume($queue, '', false, false, false, false, [$this, 'worker']);
            while (sizeof($this->channel[$queue]->callbacks)) {
                $this->channel[$queue]->wait();
            }
            return true;
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
            $queue = $this->declareChannel($channel, $queue);
            $message = new AMQPMessage(json_encode(['jobClass' => get_class($job), 'payload' => $job->getPayload()], JSON_UNESCAPED_SLASHES), [
                'delivery_mode'     => 2,
            ]);
            $this->channel[$queue]->basic_publish($message, '', $queue);

            return true;
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
            $id['channel']->basic_ack($id['delivery_tag']);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function nack($id, ?string $message = null): bool
    {
        try {
            $id['channel']->basic_nack($id['delivery_tag']);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): bool
    {
        $queues = array_keys($this->channel);
        foreach ($queues as $queue) {
            $this->channel[$queue]->close();
            $this->failed[$queue]->close();
        }
        $this->connection->close();

        return true;
    }

}
