<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://hyperf.org
 * @document https://wiki.hyperf.org
 * @contact  group@hyperf.org
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\AsyncQueue;

abstract class Job implements JobInterface
{
    /**
     * @var int
     */
    protected $maxAttempts = 1;

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
