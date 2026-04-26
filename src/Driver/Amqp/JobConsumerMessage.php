<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver\Amqp;

use Hyperf\Amqp\Builder\ExchangeBuilder;
use Hyperf\Amqp\Builder\QueueBuilder;
use Hyperf\Amqp\Message\ConsumerMessage;
use Hyperf\Amqp\Message\Type;
use Hyperf\Amqp\Result;
use Hyperf\AsyncQueue\Event\RetryHandle;
use Hyperf\AsyncQueue\Exception\JobHandlingException;
use Hyperf\AsyncQueue\Handler\JobHandler;
use Hyperf\AsyncQueue\MessageInterface;
use Hyperf\Contract\PackerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Hyperf\AsyncQueue\Enum\Result as QueueResult;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class JobConsumerMessage extends ConsumerMessage
{
    protected Type|string|null $delayType = null;
    protected ?string $failedExchange = null;
    protected ?string $failedRouteKey = null;
    protected bool $useDelayedExchange = true;
    protected ?int $prefetchCount = null;
    protected bool $queueDurable = true;
    protected bool $queueAutoDelete = false;
    protected bool $exchangeAutoDelete = false;
    protected array $queueArguments = [];

    public function __construct(
        protected AmqpDriverAdapter $driver,
        protected PackerInterface $packer,
        protected JobHandler $jobHandler,
        protected ?EventDispatcherInterface $event,
        protected ?string $queuePool
    ) {
        $this->type = 'x-delayed-message';
    }

    public function consumeMessage($data, AMQPMessage $message): Result
    {
        if ($data instanceof MessageInterface) {
            try {
                return $this->consume($data);
            } catch(JobHandlingException $e) {
                if (QueueResult::NACK === $e->result) {
                    $this->driver->retry($data);

                    return Result::ACK;
                }

                $this->driver->recordFailedMessage($data->getId(), $message->getBody(), $e);
            }
            catch (\Throwable $throwable) {
                if ($data->attempts()) {
                    $this->event?->dispatch(new RetryHandle($data, $throwable, $this->queuePool));
                    $this->driver->retry($data);

                    return Result::ACK;
                }

                $this->driver->recordFailedMessage($data->getId(), $message->getBody(), $throwable);
            }
        }

        return Result::DROP;
    }

    /**
     * @param MessageInterface $data
     *
     * @return Result
     * @throws JobHandlingException
     */
    public function consume($data): Result
    {
        $this->jobHandler->handle($data);

        return Result::ACK;
    }

    public function setType(string|Type $type): static
    {
        $this->delayType = $type;

        return $this;
    }

    public function setUseDelayedExchange(bool $useDelayedExchange): static
    {
        $this->useDelayedExchange = $useDelayedExchange;

        if (! $useDelayedExchange) {
            $this->type = $this->delayType instanceof Type ? $this->delayType->value : ($this->delayType ?? Type::DIRECT->value);
        } else {
            $this->type = 'x-delayed-message';
        }

        return $this;
    }

    public function setPrefetchCount(?int $prefetchCount): static
    {
        $this->prefetchCount = $prefetchCount;

        return $this;
    }

    public function setQueueDurable(bool $queueDurable): static
    {
        $this->queueDurable = $queueDurable;

        return $this;
    }

    public function setQueueAutoDelete(bool $queueAutoDelete): static
    {
        $this->queueAutoDelete = $queueAutoDelete;

        return $this;
    }

    public function setExchangeAutoDelete(bool $exchangeAutoDelete): static
    {
        $this->exchangeAutoDelete = $exchangeAutoDelete;

        return $this;
    }

    public function setQueueArguments(array $queueArguments): static
    {
        $this->queueArguments = $queueArguments;

        return $this;
    }

    public function reRouteFailed(string $exchange, string $destination): static
    {
        $this->failedExchange = $exchange;
        $this->failedRouteKey = $destination;

        return $this;
    }

    public function unserialize(string $data)
    {
        $payload = $data;

        // Unwrap metadata envelope if present
        $decoded = json_decode($data, true);
        if (is_array($decoded) && isset($decoded['_meta'], $decoded['_payload'])) {
            $payload = $decoded['_payload'];
        }

        return $this->packer->unpack($payload);
    }

    public function getQos(): ?array
    {
        if ($this->prefetchCount !== null) {
            return [
                'prefetch_size' => 0,
                'prefetch_count' => $this->prefetchCount,
                'global' => false,
            ];
        }

        return parent::getQos();
    }

    public function getQueueBuilder(): QueueBuilder
    {
        $builder = parent::getQueueBuilder();
        $builder->setDurable($this->queueDurable);
        $builder->setAutoDelete($this->queueAutoDelete);

        $arguments = $this->queueArguments;

        if (null !== $this->failedExchange && null !== $this->failedRouteKey) {
            $arguments['x-dead-letter-exchange'] = $this->failedExchange;
            $arguments['x-dead-letter-routing-key'] = $this->failedRouteKey;
        }

        if (! empty($arguments)) {
            $builder->setArguments(new AMQPTable($arguments));
        }

        return $builder;
    }

    public function getExchangeBuilder(): ExchangeBuilder
    {
        $delayType = is_string($this->delayType) ? $this->delayType : ($this->delayType?->value ?? Type::DIRECT->value);

        if (! $this->useDelayedExchange) {
            return (new ExchangeBuilder())->setExchange($this->getExchange())
                ->setType($delayType)
                ->setAutoDelete($this->exchangeAutoDelete);
        }

        return (new ExchangeBuilder())->setExchange($this->getExchange())
            ->setType($this->getType())
            ->setAutoDelete($this->exchangeAutoDelete)
            ->setArguments(new AMQPTable(['x-delayed-type' => $delayType]));
    }
}
