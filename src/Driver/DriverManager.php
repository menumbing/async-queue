<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver;

use Hyperf\AsyncQueue\Event\AfterPush;
use Hyperf\AsyncQueue\Event\BeforePush;
use Hyperf\AsyncQueue\Event\PushFailed;
use Hyperf\AsyncQueue\JobInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class DriverManager
{
    public function __construct(
        protected DriverFactoryInterface $factory,
        protected ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function push(JobInterface $job, int $delay = 0, string $pool = 'default'): void
    {
        $startTime = microtime(true);

        $this->eventDispatcher?->dispatch(new BeforePush($job, $pool, $delay, $startTime));

        try {
            $this->factory->get($pool)->push($job, $delay);
            $this->eventDispatcher?->dispatch(new AfterPush($job, $pool, $delay, $startTime, microtime(true)));
        } catch (\Throwable $e) {
            $this->eventDispatcher?->dispatch(new PushFailed($e, $job, $pool, $delay, $startTime, microtime(true)));

            throw $e;
        }
    }

    public function consume(string $pool = 'default'): void
    {
        $this->factory->get($pool)->consume();
    }

    public function reload(mixed $data, string $pool = 'default'): bool
    {
        return $this->factory->get($pool)->reload($data);
    }

    public function info(string $pool = 'default'): array
    {
        return $this->factory->get($pool)->info();
    }
}
