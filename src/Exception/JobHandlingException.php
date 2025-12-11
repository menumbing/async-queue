<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Exception;

use Exception;
use Hyperf\AsyncQueue\Enum\Result;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class JobHandlingException extends Exception
{
    public function __construct(public readonly Result $result, \Throwable $previous)
    {
        parent::__construct(message: 'Failed handle message', previous: $previous);
    }
}
