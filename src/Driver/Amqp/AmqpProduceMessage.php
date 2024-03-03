<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver\Amqp;

use Hyperf\Amqp\Builder\ExchangeBuilder;
use Hyperf\Amqp\Message\ProducerMessage;
use Hyperf\AsyncQueue\MessageInterface;
use Hyperf\Contract\PackerInterface;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class AmqpProduceMessage extends ProducerMessage
{
    public function __construct(MessageInterface $jobMessage, protected PackerInterface $packer)
    {
        $this->properties = [
            ...$this->properties,
            'message_id' => $jobMessage->getId(),
        ];

        $this->payload = $jobMessage;
    }

    public function setDelayMs(int $millisecond, string $name = 'x-delay'): static
    {
        return $this->setApplicationHeader($name, $millisecond);
    }

    /**
     * Overwrite.
     */
    public function getExchangeBuilder(): ExchangeBuilder
    {
        return (new ExchangeBuilder())->setExchange($this->getExchange())
            ->setType('x-delayed-message')
            ->setArguments(new AMQPTable(['x-delayed-type' => $this->getTypeString()]));
    }

    public function serialize(): string
    {
        return $this->packer->pack($this->payload);
    }

    protected function setApplicationHeader($key, $value): static
    {
        $applicationHeader = $this->properties['application_headers'] ?? new AMQPTable();
        $applicationHeader->set($key, $value);

        $this->properties['application_headers'] = $applicationHeader;

        return $this;
    }
}
