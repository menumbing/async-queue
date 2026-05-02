<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Event;

use Hyperf\AsyncQueue\JobInterface;

abstract class PushEvent
{
    public function __construct(
        public readonly JobInterface $job,
        public readonly string $pool,
        public readonly int $delay,
        public readonly float $startTime,
        public readonly ?float $endTime = null,
    ) {
    }
}
