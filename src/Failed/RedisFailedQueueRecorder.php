<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Failed;

use Generator;
use Hyperf\Codec\Packer\PhpSerializerPacker;
use Hyperf\Contract\PackerInterface;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Menumbing\Contract\AsyncQueue\FailedQueueRecorderInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class RedisFailedQueueRecorder implements FailedQueueRecorderInterface
{
    const AVAILABLE_POOLS = 'available_pools';
    const ALL_POOLS = 'all_pools';

    protected PackerInterface $packer;

    protected RedisProxy $redis;

    protected string $prefix;

    protected ?string $group;

    protected string $keyCollectorPrefix;

    public function __construct(protected ContainerInterface $container, array $options = [])
    {
        $this->group = $options['group'] ?? null;
        $this->redis = $container->get(RedisFactory::class)->get($options['pool'] ?? 'default');
        $this->packer = $container->get($options['packer'] ?? PhpSerializerPacker::class);
        $this->prefix = $options['prefix'] ?? 'failed_messages';
        $this->keyCollectorPrefix = $options['key_collector_prefix'] ?? 'failed_message_keys';
    }

    public function record(string $id, string $pool, string $payload, Throwable $exception): void
    {
        $values = [
            'id' => $id,
            'pool' => $pool,
            'payload' => $payload,
            'exception' => (string) $exception,
        ];

        $this->redis->set($this->getKey($id), $this->packer->pack($values));

        $this->addKey(static::AVAILABLE_POOLS, $pool);
        $this->addKey(static::ALL_POOLS, $id);
        $this->addKey($pool, $id);
    }

    public function all(?string $pool = null, array $criteria = []): Generator
    {
        foreach ($this->scan($pool) as $id) {
            yield $this->get($this->getKey($id));
        }
    }

    public function count(?string $pool = null, array $criteria = []): int
    {
        return (int) $this->redis->zCard($this->getCollectorKey($pool ?? static::ALL_POOLS));
    }

    public function find(string $id): ?object
    {
        return $this->get($this->getKey($id));
    }

    public function forget(string $id): bool
    {
        foreach ($this->allPools() as $pool) {
            $this->delKey($pool, $id);

            if (empty($this->count($pool))) {
                $this->delKey(static::AVAILABLE_POOLS, $pool);
            }
        }

        $this->delKey(static::ALL_POOLS, $id);

        return (bool) $this->redis->del($this->getKey($id));
    }

    public function flush(?string $pool = null, array $criteria = []): int
    {
        $num = 0;
        foreach ($this->scan($pool) as $id) {
            $this->forget($id);

            $num++;
        }

        return $num;
    }

    protected function get(string $id): ?object
    {
        if ($data = $this->redis->get($id)) {
            return (object) $this->packer->unpack($this->redis->get($id));
        }

        return null;
    }

    protected function scan(?string $pool = null): Generator
    {
        $iterator = null;

        while (true) {
            $keys = $this->redis->zScan($this->getCollectorKey($pool ?? static::ALL_POOLS), $iterator, count: 1000);
            if (! empty($keys)) {
                foreach (array_keys($keys) as $key) {
                    yield $key;
                }
            }

            if (empty($iterator)) {
                break;
            }
        }
    }

    protected function allPools(): array
    {
        return (array) $this->redis->zRange($this->getCollectorKey(static::AVAILABLE_POOLS), 0, -1);
    }

    protected function addKey(string $pool, string $key): bool|int
    {
        return (bool) $this->redis->zAdd($this->getCollectorKey($pool), floor(microtime(true) * 1000), $key);
    }

    protected function delKey(string $pool, string $key): bool
    {
        return (bool) $this->redis->zRem($this->getCollectorKey($pool), $key);
    }

    protected function getCollectorKey(string $pool): string
    {
        if (null !== $this->group) {
            return $this->group . '.' . $this->keyCollectorPrefix . ':' . $pool;
        }

        return $this->keyCollectorPrefix . ':' . $pool;
    }

    protected function getKey(string $key): string
    {
        if (null !== $this->group) {
            return $this->group . '.' . $this->prefix . ':' . $key;
        }

        return $this->prefix . ':' . $key;
    }
}
