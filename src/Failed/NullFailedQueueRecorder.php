<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Failed;

use Generator;
use Menumbing\Contract\AsyncQueue\FailedQueueRecorderInterface;
use Throwable;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class NullFailedQueueRecorder implements FailedQueueRecorderInterface
{
    public function record(string $id, string $pool, string $payload, Throwable $exception): void
    {
    }

    public function all(?string $pool = null, array $criteria = []): Generator
    {
        yield;
    }

    public function count(?string $pool = null, array $criteria = []): int
    {
        return 0;
    }

    public function find(string $id): ?object
    {
        return null;
    }

    public function forget(string $id): bool
    {
        return true;
    }

    public function flush(?string $pool = null, array $criteria = []): int
    {
        return 0;
    }
}
