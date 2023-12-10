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
namespace InitPHP\Queue\Interfaces;

interface JobInterface
{

    /**
     * @param AdapterInterface $adapter
     * @return self
     */
    public function setAdapter(AdapterInterface $adapter): self;

    /**
     * @return AdapterInterface
     */
    public function getAdapter(): AdapterInterface;

    /**
     * @param string $queue
     * @return self
     */
    public function setQueue(string $queue): self;

    /**
     * @return string
     */
    public function getQueue(): string;

    /**
     * @param string $channel
     * @return self
     */
    public function setChannel(string $channel): self;

    /**
     * @return string
     */
    public function getChannel(): string;

    /**
     * @param string|array $payload
     * @return self
     */
    public function setPayload($payload): self;

    /**
     * @return array
     */
    public function getPayload(): array;

    /**
     * @param mixed $id
     * @return self
     */
    public function setId($id): self;

    /**
     * @return mixed
     */
    public function getId();

    /**
     * @param string|null $message
     * @return bool
     */
    public function ack(?string $message = null): bool;

    /**
     * @param string|null $message
     * @return bool
     */
    public function nack(?string $message = null): bool;

    /**
     * @return bool
     */
    public function handle(): bool;

    /**
     * @param array|null $payload
     * @return bool
     */
    public function push(?array $payload = null): bool;

}
