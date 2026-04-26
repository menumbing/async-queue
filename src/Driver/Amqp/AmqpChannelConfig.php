<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver\Amqp;

use Hyperf\AsyncQueue\Driver\ChannelConfigInterface;
use Hyperf\AsyncQueue\Exception\InvalidQueueException;

/**
 * AMQP-specific channel configuration implementing ChannelConfigInterface.
 * Uses dot (.) separator for AMQP naming conventions instead of colon (:) used by Redis.
 *
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class AmqpChannelConfig implements ChannelConfigInterface
{
    protected string $channel;
    protected string $exchange;
    protected string $queue;
    protected string $routingKey;
    protected string $delayExchange;
    protected string $delayQueue;
    protected string $failedExchange;
    protected string $failedQueue;
    protected string $failedRoutingKey;

    public function __construct(string $channel, array $amqpConfig, string $appName, string $pool)
    {
        $this->channel = $channel;

        $this->exchange = $amqpConfig['exchange'] ?? $channel;

        $this->queue = $amqpConfig['queue'] ?? $this->exchange . '.' . $appName;
        $this->routingKey = $amqpConfig['routing_key'] ?? $this->exchange;

        $this->delayExchange = $amqpConfig['delay_exchange'] ?? $channel . '.delayed';
        $this->delayQueue = $amqpConfig['delay_queue'] ?? $this->queue . '.delay';

        $this->failedExchange = array_key_exists('failed_exchange', $amqpConfig)
            ? $amqpConfig['failed_exchange']
            : $channel . '.failed';
        $this->failedQueue = $amqpConfig['failed_queue'] ?? $this->queue . '.failed';
        $this->failedRoutingKey = $amqpConfig['failed_routing_key'] ?? $pool;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function get(string $queue): string
    {
        if (property_exists($this, $queue) && is_string($this->{$queue})) {
            return $this->{$queue};
        }

        throw new InvalidQueueException(sprintf('Queue %s is not exist.', $queue));
    }

    public function getWaiting(): string
    {
        return $this->exchange;
    }

    public function getReserved(): string
    {
        return $this->channel . '.reserved';
    }

    public function getTimeout(): string
    {
        return $this->channel . '.timeout';
    }

    public function getDelayed(): string
    {
        return $this->delayExchange;
    }

    public function getFailed(): string
    {
        return $this->failedExchange;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function getDelayQueue(): string
    {
        return $this->delayQueue;
    }

    public function getFailedQueue(): string
    {
        return $this->failedQueue;
    }

    public function getFailedRoutingKey(): string
    {
        return $this->failedRoutingKey;
    }
}
