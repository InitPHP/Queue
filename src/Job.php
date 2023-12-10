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
namespace InitPHP\Queue;

use Throwable;
use InitPHP\Queue\Interfaces\JobInterface;
use InitPHP\Queue\Exceptions\JobInvalidArgumentException;
use InitPHP\Queue\Interfaces\AdapterInterface;

abstract class Job implements JobInterface
{

    private AdapterInterface $adapter;

    protected string $channel = 'c';

    protected string $queue = 'queue';

    /** @var mixed */
    private $id;

    private array $payload = [];

    private bool $nack = false;

    private bool $ack = false;

    public function __construct(AdapterInterface $adapter)
    {
        $this->setAdapter($adapter);
    }

    /**
     * @inheritDoc
     */
    public function setAdapter(AdapterInterface $adapter): self
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * @inheritDoc
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * @inheritDoc
     */
    public function setChannel(string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @inheritDoc
     */
    public function setId($id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getId()
    {
        return $this->id ?? null;
    }

    /**
     * @inheritDoc
     */
    public function setPayload($payload): self
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            throw new JobInvalidArgumentException();
        }
        $this->payload = $payload;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @inheritDoc
     */
    public function ack(?string $message = null): bool
    {
        if (!$this->ack) {
            $this->ack = true;
            return $this->getAdapter()->ack($this->id, $message);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function nack(?string $message = null): bool
    {
        if (!$this->nack) {
            $this->nack = true;
            return $this->getAdapter()->nack($this->id, $message);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    abstract public function handle(): bool;

    /**
     * @inheritDoc
     */
    public function push(?array $payload = null): bool
    {
        try {
            $payload !== null && $this->setPayload($payload);
            $this->getAdapter()->push($this->getChannel(), $this->getQueue(), $this);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

}
