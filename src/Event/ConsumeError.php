<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Event;

use Throwable;

final class ConsumeError
{
    public function __construct(
        public readonly Throwable $throwable,
        public readonly string $pool,
    ) {
    }
}
