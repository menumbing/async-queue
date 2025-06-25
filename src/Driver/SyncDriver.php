<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver;

use Hyperf\AsyncQueue\JobInterface;
use Hyperf\AsyncQueue\MessageInterface;

/**
 * @author  Aldi Arief <aldiarief598@gmail.com>
 */
class SyncDriver extends Driver
{
    protected function retry(MessageInterface $message): bool
    {
        return false;
    }

    protected function remove(mixed $data): bool
    {
        return false;
    }

    public function push(JobInterface $job, int $delay = 0): bool
    {
        $job->handle();

        return true;
    }

    public function delete(MessageInterface $message): bool
    {
        return false;
    }

    public function pop(): array
    {
        return [false, null];
    }

    public function ack(mixed $data): bool
    {
        return false;
    }

    public function fail(mixed $data): bool
    {
        return false;
    }

    public function reload(mixed $data): bool
    {
        return false;
    }

    public function reloadAll(string $queue = null): int
    {
        return 0;
    }

    public function flush(string $queue = null): bool
    {
        return false;
    }

    public function info(): array
    {
        return [];
    }
}