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
    protected bool $useDelayedExchange = true;

    protected bool $exchangeAutoDelete = false;

    protected array $metadata = [];

    public function __construct(MessageInterface $jobMessage, protected PackerInterface $packer)
    {
        $this->properties = [
            ...$this->properties,
            'message_id' => $jobMessage->getId(),
        ];

        $this->setApplicationHeader('x-message-name', get_class($jobMessage->job()));
        $this->setApplicationHeader('x-message-id', $jobMessage->getId());

        $this->payload = $jobMessage;
    }

    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function setUseDelayedExchange(bool $useDelayedExchange): static
    {
        $this->useDelayedExchange = $useDelayedExchange;

        return $this;
    }

    public function setExchangeAutoDelete(bool $exchangeAutoDelete): static
    {
        $this->exchangeAutoDelete = $exchangeAutoDelete;

        return $this;
    }

    public function setDelayMs(int $millisecond, string $name = 'x-delay'): static
    {
        return $this->setApplicationHeader($name, $millisecond);
    }

    public function setTtlMs(int $millisecond): static
    {
        $this->properties['expiration'] = (string) $millisecond;

        return $this;
    }

    /**
     * Overwrite.
     */
    public function getExchangeBuilder(): ExchangeBuilder
    {
        if (! $this->useDelayedExchange) {
            return (new ExchangeBuilder())->setExchange($this->getExchange())
                ->setType($this->getTypeString())
                ->setAutoDelete($this->exchangeAutoDelete);
        }

        return (new ExchangeBuilder())->setExchange($this->getExchange())
            ->setType('x-delayed-message')
            ->setAutoDelete($this->exchangeAutoDelete)
            ->setArguments(new AMQPTable(['x-delayed-type' => $this->getTypeString()]));
    }

    public function serialize(): string
    {
        $packed = $this->packer->pack($this->payload);

        if (! empty($this->metadata)) {
            return json_encode([
                '_meta' => $this->metadata,
                '_payload' => $packed,
            ], JSON_UNESCAPED_SLASHES);
        }

        return $packed;
    }

    protected function setApplicationHeader($key, $value): static
    {
        $applicationHeader = $this->properties['application_headers'] ?? new AMQPTable();
        $applicationHeader->set($key, $value);

        $this->properties['application_headers'] = $applicationHeader;

        return $this;
    }
}
