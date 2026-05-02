<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Event;

use Hyperf\AsyncQueue\JobInterface;
use Throwable;

final class PushFailed
{
    public function __construct(
        public readonly Throwable $throwable,
        public readonly JobInterface $job,
        public readonly string $pool,
        public readonly int $delay,
        public readonly float $startTime,
        public readonly ?float $endTime = null,
    ) {
    }
}
