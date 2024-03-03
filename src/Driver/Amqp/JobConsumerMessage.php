<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver\Amqp;

use Hyperf\Amqp\Builder\ExchangeBuilder;
use Hyperf\Amqp\Builder\QueueBuilder;
use Hyperf\Amqp\Message\ConsumerMessage;
use Hyperf\Amqp\Message\Type;
use Hyperf\Amqp\Result;
use Hyperf\AsyncQueue\Event\AfterHandle;
use Hyperf\AsyncQueue\Event\BeforeHandle;
use Hyperf\AsyncQueue\Event\FailedHandle;
use Hyperf\AsyncQueue\Event\RetryHandle;
use Hyperf\AsyncQueue\MessageInterface;
use Hyperf\Contract\PackerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class JobConsumerMessage extends ConsumerMessage
{
    protected Type|string|null $delayType = null;
    protected ?string $failedExchange = null;
    protected ?string $failedRouteKey = null;

    public function __construct(
        protected AmqpDriverAdapter $driver,
        protected PackerInterface $packer,
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
            } catch (\Throwable $throwable) {
                if ($data->attempts()) {
                    $this->event?->dispatch(new RetryHandle($data, $throwable, $this->queuePool));
                    $this->driver->retry($data);

                    return Result::ACK;
                }

                $this->event?->dispatch(new FailedHandle($data, $throwable, $this->queuePool));
                $this->driver->recordFailedMessage($data->getId(), $message->getBody(), $throwable);
                $data->job()->fail($throwable);
            }
        }

        return Result::DROP;
    }

    public function consume($data): Result
    {
        $this->event?->dispatch(new BeforeHandle($data, $this->queuePool));
        $data->job()->handle();
        $this->event?->dispatch(new AfterHandle($data, $this->queuePool));

        return Result::ACK;
    }

    public function setType(string|Type $type): static
    {
        $this->delayType = $type;

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
        return $this->packer->unpack($data);
    }

    public function getQueueBuilder(): QueueBuilder
    {
        $builder = parent::getQueueBuilder();

        if (null !== $this->failedExchange && null !== $this->failedRouteKey) {
            $builder->setArguments(new AMQPTable([
                'x-dead-letter-exchange' => $this->failedExchange,
                'x-dead-letter-routing-key' => $this->failedRouteKey,
            ]));
        }

        return $builder;
    }

    public function getExchangeBuilder(): ExchangeBuilder
    {
        $delayType = is_string($this->delayType) ? $this->delayType : $this->delayType->value;

        return (new ExchangeBuilder())->setExchange($this->getExchange())
            ->setType($this->getType())
            ->setArguments(new AMQPTable(['x-delayed-type' => $delayType]));
    }
}
