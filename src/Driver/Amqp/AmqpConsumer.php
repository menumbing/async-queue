<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver\Amqp;

use Hyperf\Amqp\ConnectionFactory;
use Hyperf\Amqp\Consumer;
use Hyperf\Coroutine\Concurrent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class AmqpConsumer extends Consumer
{
    protected ?Concurrent $concurrent = null;

    public function __construct(
        ContainerInterface $container,
        ConnectionFactory $factory,
        LoggerInterface $logger,
        protected array $config
    ) {
        parent::__construct($container, $factory, $logger);

        $concurrentLimit = $config['concurrent']['limit'] ?? null;
        if ($concurrentLimit && is_numeric($concurrentLimit)) {
            $this->concurrent = new Concurrent((int) $concurrentLimit);
        }
    }

    protected function getConcurrent(string $pool): ?Concurrent
    {
        return $this->concurrent;
    }
}
