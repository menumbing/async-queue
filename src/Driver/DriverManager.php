<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver;

use Hyperf\AsyncQueue\JobInterface;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class DriverManager
{
    public function __construct(protected DriverFactoryInterface $factory)
    {
    }

    public function push(JobInterface $job, int $delay = 0, string $pool = 'default'): void
    {
        $this->factory->get($pool)->push($job, $delay);
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
