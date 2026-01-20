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
        $this->getTable()->insert(array_filter([
            'id'        => $id,
            'group'     => $this->group,
            'pool'      => $pool,
            'payload'   => base64_encode($payload),
            'exception' => (string)$exception,
            'failed_at' => (new DateTime())->format('Y-m-d H:i:s'),
        ]));
    }

    public function all(?string $pool = null, array $criteria = []): Generator
    {
        if (null === $query = $this->prepareRangeQuery($pool)) {
            return new Generator();
        }

        $keys = [
            'id',
            'group',
            'pool',
            'payload',
            'exception',
            'failed_at',
        ];

        foreach ($query->cursor() as $item) {
            yield $this->decode((object) array_combine($keys, $item));
        }
    }

    public function count(?string $pool = null, array $criteria = []): int
    {
        if (null === $query = $this->prepareRangeQuery($pool)) {
            return 0;
        }

        return $query->count();
    }

    public function find(string $id): ?object
    {
        if ($message = $this->getTable()->find($id)) {
            return $this->decode((object) $message);
        }

        return null;
    }

    public function forget(string $id): bool
    {
        return $this->getTable()->delete($id) > 0;
    }

    public function flush(?string $pool = null, array $criteria = []): int
    {
        return $this->getTable($pool)->delete();
    }

    protected function decode(object $data): object
    {
        if (false !== $payload = base64_decode($data->payload ?? '', true)) {
            $data->payload = $payload;
        }

        return $data;
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

    protected function prepareRangeQuery(?string $pool): ?Builder
    {
        $firstFailedAt = $this->getTable($pool)->oldest('failed_at')->first()['failed_at'] ?? null;
        $lastFailedAt = $this->getTable($pool)->latest('failed_at')->first()['failed_at'] ?? null;

        if (!$firstFailedAt && !$lastFailedAt) {
            return null;
        }

        return $this->getTable($pool)->whereBetween('failed_at', [$firstFailedAt, $lastFailedAt])->oldest('failed_at');
    }
}
