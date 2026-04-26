<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver\Amqp;

use Hyperf\Amqp\Builder\QueueBuilder;
use Hyperf\Amqp\ConnectionFactory;
use Hyperf\Amqp\Consumer;
use Hyperf\Amqp\Message\ConsumerMessage;
use Hyperf\Amqp\Message\ConsumerMessageInterface;
use Hyperf\Amqp\Message\ProducerMessageInterface;
use Hyperf\Amqp\Message\Type;
use Hyperf\Amqp\Producer;
use PhpAmqpLib\Wire\AMQPTable;
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

    protected AmqpChannelConfig $channel;

    protected JobHandler $jobHandler;

    protected Type $exchangeType;

    protected array|int $retrySeconds;

    protected int $handleTimeout;

    protected int $maxMessages;

    protected bool $rerouteFailed;

    protected string $connection;

    protected ConnectionFactory $connectionFactory;

    protected ?int $prefetchCount;

    protected bool $queueDurable;

    protected bool $queueAutoDelete;

    protected array $queueArguments;

    protected bool $exchangeAutoDelete;

    protected bool $useDelayedExchange;

    public function __construct(ContainerInterface $container, array $config)
    {
        parent::__construct($container, $config);

        $this->connectionFactory = $container->get(ConnectionFactory::class);
        $this->producer = $container->get(Producer::class);
        $this->consumer = new AmqpConsumer(
            $container,
            $this->connectionFactory,
            $container->get(LoggerInterface::class),
            $config
        );
        $this->jobHandler = $container->get(JobHandler::class);

        $appName = $container->get(ConfigInterface::class)->get('app_name', 'hyperf');

        $this->exchangeType = $config['amqp']['exchange_type'] ?? Type::DIRECT;
        $this->rerouteFailed = $config['amqp']['reroute_failed'] ?? false;
        $this->retrySeconds = $config['retry_seconds'] ?? 10;
        $this->handleTimeout = $config['handle_timeout'] ?? 10;
        $this->maxMessages = $config['max_messages'] ?? 0;
        $this->connection = $config['amqp']['pool'] ?? 'default';
        $this->useDelayedExchange = $config['amqp']['use_delayed_exchange'] ?? true;
        $this->prefetchCount = $config['amqp']['prefetch_count'] ?? null;
        $this->queueDurable = $config['amqp']['queue_durable'] ?? true;
        $this->queueAutoDelete = $config['amqp']['queue_auto_delete'] ?? false;
        $this->queueArguments = $config['amqp']['queue_arguments'] ?? [];
        $this->exchangeAutoDelete = $config['amqp']['exchange_auto_delete'] ?? false;

        $this->channel = new AmqpChannelConfig(
            $config['channel'] ?? 'queue',
            $config['amqp'] ?? [],
            $appName,
            $this->pool
        );
    }

    public function push(JobInterface $job, int $delay = 0): bool
    {
        $message = make(JobMessage::class, [$job, (string) Str::uuidv7(), $this->pool]);

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
        $consumerMessage = $this->createConsumeMessage($this->channel->getWaiting(), $this->channel->getQueue());

        if (! $this->useDelayedExchange) {
            // Declare delay exchange and queue for TTL-based delay
            $this->consumer->declare(
                $this->createDelayConsumeMessage(
                    $this->channel->getDelayed(),
                    $this->channel->getDelayQueue(),
                    $this->channel->getWaiting(),
                    $this->channel->getRoutingKey()
                )
            );
        }

        if ($this->rerouteFailed) {
            // Declare failed exchange and queue
            $this->consumer->declare(
                $this->createFailedConsumeMessage($this->channel->getFailed(), $this->channel->getFailedQueue())
            );

            $consumerMessage->reRouteFailed($this->channel->getFailed(), $this->channel->getFailedRoutingKey());
        }

        $this->consumer->consume($consumerMessage);
    }

    public function reload(mixed $data): bool
    {
        $payload = $data;

        // Unwrap metadata envelope if present
        $decoded = json_decode($data, true);
        if (is_array($decoded) && isset($decoded['_meta'], $decoded['_payload'])) {
            $payload = $decoded['_payload'];
        }

        /** @var MessageInterface $message */
        $message = $this->packer->unpack($payload);

        return $this->push($message->job());
    }

    public function reloadAll(string $queue = null): int
    {
        $num = 0;

        foreach ($this->failedQueueRecorder->all($this->pool) as $item) {
            $this->reload($item->payload);
            $this->failedQueueRecorder->forget($item->id);
            ++$num;
        }

        return $num;
    }

    public function flush(string $queue = null): bool
    {
        $count = $this->failedQueueRecorder->flush($this->pool);

        if ($this->rerouteFailed) {
            try {
                $connection = $this->connectionFactory->getConnection($this->connection);
                $channel = $connection->getChannel();

                try {
                    $channel->queue_purge($this->channel->getFailedQueue());
                } finally {
                    $connection->releaseChannel($channel);
                }
            } catch (\Throwable) {
                // Failed queue may not exist yet
            }
        }

        return $count > 0;
    }

    public function info(): array
    {
        $info = [
            'waiting' => 0,
            'consumers' => 0,
            'failed' => $this->failedQueueRecorder->count($this->pool),
        ];

        try {
            $connection = $this->connectionFactory->getConnection($this->connection);
            $channel = $connection->getChannel();

            try {
                [, $messageCount, $consumerCount] = $channel->queue_declare($this->channel->getQueue(), true);
                $info['waiting'] = $messageCount;
                $info['consumers'] = $consumerCount;
                $connection->releaseChannel($channel);
            } catch (\Throwable) {
                // Queue does not exist yet — channel becomes unusable after failed passive declare
            }

            if ($this->rerouteFailed) {
                try {
                    $channel = $connection->getChannel();
                    [, $failedQueueCount] = $channel->queue_declare($this->channel->getFailedQueue(), true);
                    $info['failed_queue'] = $failedQueueCount;
                    $connection->releaseChannel($channel);
                } catch (\Throwable) {
                    // Failed queue does not exist yet
                }
            }
        } catch (\Throwable) {
            // Connection error — return minimal info
        }

        return $info;
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
        $consumerMessage->setRoutingKey($this->channel->getRoutingKey());
        $consumerMessage->setQueue($queue);
        $consumerMessage->setMaxConsumption($this->maxMessages);
        $consumerMessage->setPoolName($this->connection);
        $consumerMessage->setUseDelayedExchange($this->useDelayedExchange);
        $consumerMessage->setQueueDurable($this->queueDurable);
        $consumerMessage->setQueueAutoDelete($this->queueAutoDelete);
        $consumerMessage->setQueueArguments($this->queueArguments);
        $consumerMessage->setExchangeAutoDelete($this->exchangeAutoDelete);

        if ($this->prefetchCount !== null) {
            $consumerMessage->setPrefetchCount($this->prefetchCount);
        }

        return $consumerMessage;
    }

    protected function createFailedConsumeMessage(string $exchange, string $queue): ConsumerMessageInterface
    {
        $exchangeAutoDelete = $this->exchangeAutoDelete;

        $consumerMessage = new class ($exchangeAutoDelete) extends ConsumerMessage {
            public function __construct(private readonly bool $exchangeAutoDelete)
            {
            }

            public function getExchangeBuilder(): \Hyperf\Amqp\Builder\ExchangeBuilder
            {
                $builder = parent::getExchangeBuilder();
                $builder->setAutoDelete($this->exchangeAutoDelete);

                return $builder;
            }
        };

        $consumerMessage->setExchange($exchange);
        $consumerMessage->setType($this->exchangeType);
        $consumerMessage->setRoutingKey($this->channel->getFailedRoutingKey());
        $consumerMessage->setQueue($queue);
        $consumerMessage->setPoolName($this->connection);

        return $consumerMessage;
    }

    /**
     * Create a consumer message for the delay exchange/queue (TTL + DLX approach).
     * Messages published here expire after their TTL and route to the main exchange via dead-letter exchange.
     */
    protected function createDelayConsumeMessage(
        string $exchange,
        string $queue,
        string $dlxExchange,
        string $dlxRoutingKey
    ): ConsumerMessageInterface {
        $queueDurable = $this->queueDurable;
        $queueAutoDelete = $this->queueAutoDelete;
        $exchangeAutoDelete = $this->exchangeAutoDelete;

        $consumerMessage = new class ($dlxExchange, $dlxRoutingKey, $queueDurable, $queueAutoDelete, $exchangeAutoDelete) extends ConsumerMessage {
            public function __construct(
                private readonly string $dlxExchange,
                private readonly string $dlxRoutingKey,
                private readonly bool $isDurable,
                private readonly bool $isAutoDelete,
                private readonly bool $isExchangeAutoDelete,
            ) {
            }

            public function getQueueBuilder(): QueueBuilder
            {
                $builder = parent::getQueueBuilder();
                $builder->setDurable($this->isDurable);
                $builder->setAutoDelete($this->isAutoDelete);
                $builder->setArguments(new AMQPTable([
                    'x-dead-letter-exchange' => $this->dlxExchange,
                    'x-dead-letter-routing-key' => $this->dlxRoutingKey,
                ]));

                return $builder;
            }

            public function getExchangeBuilder(): \Hyperf\Amqp\Builder\ExchangeBuilder
            {
                $builder = parent::getExchangeBuilder();
                $builder->setAutoDelete($this->isExchangeAutoDelete);

                return $builder;
            }
        };

        $consumerMessage->setExchange($exchange);
        $consumerMessage->setType($this->exchangeType);
        $consumerMessage->setRoutingKey($this->channel->getRoutingKey());
        $consumerMessage->setQueue($queue);
        $consumerMessage->setPoolName($this->connection);

        return $consumerMessage;
    }

    protected function createProduceMessage(MessageInterface $message, int $delay = 0): ProducerMessageInterface
    {
        $producerMessage = (new AmqpProduceMessage($message, $this->packer));
        $producerMessage->setUseDelayedExchange($this->useDelayedExchange);
        $producerMessage->setExchangeAutoDelete($this->exchangeAutoDelete);
        $producerMessage->setType($this->exchangeType);
        $producerMessage->setRoutingKey($this->channel->getRoutingKey());
        $producerMessage->setPoolName($this->connection);
        $producerMessage->setMetadata([
            'exchange' => $this->channel->getExchange(),
            'routing_key' => $this->channel->getRoutingKey(),
            'queue' => $this->channel->getQueue(),
        ]);

        if (! $this->useDelayedExchange && $delay > 0) {
            // TTL-based delay: publish to delay exchange, message expires and routes to main exchange via DLX
            $producerMessage->setExchange($this->channel->getDelayed());
            $producerMessage->setTtlMs($delay * 1000);
        } else {
            $producerMessage->setExchange($this->channel->getWaiting());

            if ($delay > 0) {
                $producerMessage->setDelayMs($delay * 1000);
            }
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
