<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Enum;

enum Result
{
    case ACK;
    case NACK;
    case DROP;
}
