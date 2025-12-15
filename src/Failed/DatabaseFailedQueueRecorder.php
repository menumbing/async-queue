<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Failed;

use DateTime;
use Generator;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Query\Builder;
use Menumbing\Contract\AsyncQueue\FailedQueueRecorderInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class DatabaseFailedQueueRecorder implements FailedQueueRecorderInterface
{
    protected string $failedTable;

    protected ?string $connection;

    protected ?string $group;

    public function __construct(protected ContainerInterface $container, array $options = [])
    {
        $this->group = $options['group'] ?? null;
        $this->failedTable = $options['failed_table'] ?? 'failed_messages';
        $this->connection = $options['connection'] ?? null;
    }

    public function record(string $id, string $pool, string $payload, Throwable $exception): void
    {
        $this->getTable()->insert([
            'id' => $id,
            'group' => $this->group,
            'pool' => $pool,
            'payload' => $payload,
            'exception' => (string) $exception,
            'failed_at' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    public function all(?string $pool = null): Generator
    {
        foreach ($this->getTable($pool)->oldest('failed_at')->cursor() as $item) {
            yield $item;
        }
    }

    public function count(?string $pool = null): int
    {
        return $this->getTable($pool)->count();
    }

    public function find(string $id): ?object
    {
        return $this->getTable()->find($id);
    }

    public function forget(string $id): bool
    {
        return $this->getTable()->delete($id) > 0;
    }

    public function flush(?string $pool = null): int
    {
        return $this->getTable($pool)->delete();
    }

    protected function getTable(?string $pool = null): Builder
    {
        $db = $this->container->get(ConnectionResolverInterface::class);

        $table = $db->connection($this->connection)->table($this->failedTable);

        if ($pool) {
            $table->where('pool', $pool);
        }

        if ($this->group) {
            $table->where('group', $this->group);
        }

        return $table;
    }
}
