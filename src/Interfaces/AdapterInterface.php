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

interface AdapterInterface
{

    /**
     * @param object $message
     * @return bool
     */
    public function worker(object $message): bool;

    /**
     * @param string $channel
     * @param string $queue
     * @return bool
     */
    public function handle(string $channel, string $queue): bool;

    /**
     * @param string $channel
     * @param string $queue
     * @param JobInterface $job
     * @return bool
     */
    public function push(string $channel, string $queue, JobInterface $job): bool;

    /**
     * @param mixed $id
     * @param string|null $message
     * @return bool
     */
    public function ack($id, ?string $message = null): bool;

    /**
     * @param mixed $id
     * @param string|null $message
     * @return bool
     */
    public function nack($id, ?string $message = null): bool;


    /**
     * @return bool
     */
    public function close(): bool;

}
