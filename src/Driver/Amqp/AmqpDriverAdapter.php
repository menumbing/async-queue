<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver\Amqp;

use Hyperf\Amqp\ConnectionFactory;
use Hyperf\Amqp\Consumer;
use Hyperf\Amqp\Message\ConsumerMessage;
use Hyperf\Amqp\Message\ConsumerMessageInterface;
use Hyperf\Amqp\Message\ProducerMessageInterface;
use Hyperf\Amqp\Message\Type;
use Hyperf\Amqp\Producer;
use Hyperf\AsyncQueue\Driver\ChannelConfig;
use Hyperf\AsyncQueue\Driver\Driver;
use Hyperf\AsyncQueue\Handler\JobHandler;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\AsyncQueue\JobMessage;
use Hyperf\AsyncQueue\MessageInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function Hyperf\Support\make;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class AmqpDriverAdapter extends Driver
{
    protected Producer $producer;

    protected Consumer $consumer;

    protected ChannelConfig $channel;

    protected JobHandler $jobHandler;

    protected Type $exchangeType;

    protected array|int $retrySeconds;

    protected int $handleTimeout;

    protected string $queue;

    protected int $maxMessages;

    protected bool $rerouteFailed;

    public function __construct(ContainerInterface $container, array $config)
    {
        parent::__construct($container, $config);

        $this->producer = $container->get(Producer::class);
        $this->consumer = new AmqpConsumer(
            $container,
            $container->get(ConnectionFactory::class),
            $container->get(LoggerInterface::class),
            $config
        );
        $this->jobHandler = $container->get(JobHandler::class);

        $appName = $container->get(ConfigInterface::class)->get('app_name', 'hyperf');

        $this->exchangeType = $config['amqp']['exchange_type'] ?? Type::DIRECT;
        $this->queue = $config['amqp']['queue'] ?? $appName . '.' . $this->pool;
        $this->rerouteFailed = $config['amqp']['reroute_failed'] ?? false;
        $this->retrySeconds = $config['retry_seconds'] ?? 10;
        $this->handleTimeout = $config['handle_timeout'] ?? 10;
        $this->maxMessages = $config['max_messages'] ?? 0;

        $this->channel = make(ChannelConfig::class, ['channel' => $config['channel'] ?? 'queue']);
    }

    public function push(JobInterface $job, int $delay = 0): bool
    {
        $message = make(JobMessage::class, [$job, (string) Str::uuid(), $this->pool]);

        return $this->producer->produce(
            $this->createProduceMessage($message, $delay),
            timeout: $this->handleTimeout
        );
    }

    public function delete(MessageInterface $message): bool
    {
        return false;
    }

    public function pop(): array
    {
        return [];
    }

    public function ack(mixed $data): bool
    {
        return false;
    }

    public function fail(mixed $data): bool
    {
        return true;
    }

    public function consume(): void
    {
        $consumerMessage = $this->createConsumeMessage($this->channel->getWaiting(), $this->queue);

        if ($this->rerouteFailed) {
            // Declare failed exchange and queue
            $this->consumer->declare(
                $this->createFailedConsumeMessage($this->channel->getFailed(), $this->queue.'.failed')
            );

            $consumerMessage->reRouteFailed($this->channel->getFailed(), $this->pool);
        }

        $this->consumer->consume($consumerMessage);
    }

    public function reload(mixed $data): bool
    {
        /** @var MessageInterface $message */
        $message = $this->packer->unpack($data);

        return $this->push($message->job());
    }

    public function reloadAll(string $queue = null): int
    {
        return 0;
    }

    public function flush(string $queue = null): bool
    {
        return false;
    }

    public function info(): array
    {
        return [
            'failed' => $this->failedQueueRecorder->count(),
        ];
    }

    public function retry(MessageInterface $message): bool
    {
        return $this->producer->produce(
            $this->createProduceMessage($message, $this->getRetrySeconds($message->getAttempts()))
        );
    }

    protected function remove(mixed $data): bool
    {
        return false;
    }

    protected function createConsumeMessage(string $exchange, string $queue): ConsumerMessageInterface
    {
        $consumerMessage = new JobConsumerMessage(
            $this,
            $this->packer,
            $this->jobHandler,
            $this->event,
            $this->pool
        );

        $consumerMessage->setExchange($exchange);
        $consumerMessage->setType($this->exchangeType);
        $consumerMessage->setRoutingKey($this->pool);
        $consumerMessage->setQueue($queue);
        $consumerMessage->setMaxConsumption($this->maxMessages);

        return $consumerMessage;
    }

    protected function createFailedConsumeMessage(string $exchange, string $queue): ConsumerMessageInterface
    {
        $consumerMessage = new class extends ConsumerMessage {};

        $consumerMessage->setExchange($exchange);
        $consumerMessage->setType($this->exchangeType);
        $consumerMessage->setRoutingKey($this->pool);
        $consumerMessage->setQueue($queue);

        return $consumerMessage;
    }

    protected function createProduceMessage(MessageInterface $message, int $delay = 0): ProducerMessageInterface
    {
        $producerMessage = new AmqpProduceMessage($message, $this->packer);

        $producerMessage->setExchange($this->channel->getWaiting());
        $producerMessage->setType($this->exchangeType);
        $producerMessage->setRoutingKey($this->pool);

        if ($delay > 0) {
            $producerMessage->setDelayMs($delay * 1000);
        }

        return $producerMessage;
    }

    protected function getRetrySeconds(int $attempts): int
    {
        if (! is_array($this->retrySeconds)) {
            return $this->retrySeconds;
        }

        if (empty($this->retrySeconds)) {
            return 10;
        }

        return $this->retrySeconds[$attempts - 1] ?? end($this->retrySeconds);
    }
}
