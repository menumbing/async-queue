<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Listener;

use Hyperf\AsyncQueue\Driver\RedisDriver;
use Hyperf\AsyncQueue\Event\AfterHandle;
use Hyperf\AsyncQueue\Event\BeforeHandle;
use Hyperf\AsyncQueue\Event\Event;
use Hyperf\AsyncQueue\Event\FailedHandle;
use Hyperf\AsyncQueue\Event\RetryHandle;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Process\Event\BeforeProcessHandle;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Psr\Container\ContainerInterface;

class QueueStatsListener implements ListenerInterface
{
    private string $group;

    private ConfigInterface $config;

    private RedisFactory $redisFactory;

    private StdoutLoggerInterface $logger;

    /** @var array<string, RedisProxy> */
    private array $poolRedisMap = [];

    /** @var array<string, string> */
    private array $poolChannelMap = [];

    private const WORKER_TTL = 90;

    /** @var array<string, string> Map of workerName => poolName */
    private array $registeredWorkers = [];

    public function __construct(ContainerInterface $container)
    {
        $this->config = $container->get(ConfigInterface::class);
        $this->redisFactory = $container->get(RedisFactory::class);
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->group = $this->config->get('app_name', 'menumbing');
    }

    public function listen(): array
    {
        return [
            BeforeProcessHandle::class,
            BeforeHandle::class,
            AfterHandle::class,
            FailedHandle::class,
            RetryHandle::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof BeforeProcessHandle) {
            $this->registerAllPools();
            $this->registerWorker($event);
            $this->startHeartbeat();

            return;
        }

        if (! $event instanceof Event) {
            return;
        }

        $message = $event->getMessage();
        $pool = $event->getPool();
        $messageId = $message->getId();

        if (! $this->isRedisPool($pool)) {
            return;
        }

        try {
            if ($event instanceof BeforeHandle) {
                $this->handleBefore($pool, $messageId);
            } elseif ($event instanceof AfterHandle) {
                $this->handleAfter($pool, $messageId);
            } elseif ($event instanceof FailedHandle) {
                $this->handleFailed($pool, $messageId);
            } elseif ($event instanceof RetryHandle) {
                $this->cleanupContext($messageId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[QueueStats] Failed to record stats for pool [%s]: %s',
                $pool,
                $e->getMessage()
            ));
        }
    }

    private function handleBefore(string $pool, string $messageId): void
    {
        $this->registerPool($pool);

        $nowMs = microtime(true) * 1000;
        Context::set("queue_stats:start:{$messageId}", $nowMs);

        $createdMs = $this->extractUuidV7Timestamp($messageId);
        $waitMs = $createdMs > 0 ? max(0, $nowMs - $createdMs) : 0.0;
        Context::set("queue_stats:wait:{$messageId}", $waitMs);
    }

    private function handleAfter(string $pool, string $messageId): void
    {
        $startMs = Context::get("queue_stats:start:{$messageId}");
        $waitMs = Context::get("queue_stats:wait:{$messageId}", 0.0);

        $this->cleanupContext($messageId);

        if ($startMs === null) {
            return;
        }

        $runtimeMs = (microtime(true) * 1000) - $startMs;
        $redis = $this->getRedisForPool($pool);
        $key = $this->getStatsKey($pool);

        $redis->hIncrBy($key, 'processed', 1);
        $redis->hIncrByFloat($key, 'runtime_total', $runtimeMs);
        $redis->hIncrByFloat($key, 'wait_total', $waitMs);
    }

    private function handleFailed(string $pool, string $messageId): void
    {
        $this->cleanupContext($messageId);

        $redis = $this->getRedisForPool($pool);
        $key = $this->getStatsKey($pool);

        $redis->hIncrBy($key, 'failed', 1);
    }

    private function cleanupContext(string $messageId): void
    {
        Context::destroy("queue_stats:start:{$messageId}");
        Context::destroy("queue_stats:wait:{$messageId}");
    }

    private function registerAllPools(): void
    {
        $pools = $this->config->get('async_queue.pools', []);

        foreach (array_keys($pools) as $pool) {
            if (! $this->isRedisPool($pool)) {
                continue;
            }

            try {
                $this->registerPool($pool);
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[QueueStats] Failed to register pool [%s] on process start: %s',
                    $pool,
                    $e->getMessage()
                ));
            }
        }
    }

    private function registerPool(string $pool): void
    {
        $redis = $this->getRedisForPool($pool);
        $prefix = $this->group ? $this->group . '.' : '';

        $redis->sAdd($prefix . 'queue_stats:pools', $pool);
        $key = $this->getStatsKey($pool);
        $redis->hSet($key, 'channel', $this->getChannelForPool($pool));
        $redis->hSet($key, 'last_active_at', (string) time());
        $redis->hSet($key, 'processes', (string) $this->config->get("async_queue.pools.{$pool}.processes", 1));
    }

    private function registerWorker(BeforeProcessHandle $event): void
    {
        $processName = $event->process->name;

        // Process name format: "queue.{poolName}"
        $pool = substr($processName, 6); // strip "queue."
        if (! $this->isRedisPool($pool)) {
            return;
        }

        $workerName = $processName . '.' . $event->index;

        try {
            $redis = $this->getRedisForPool($pool);
            $prefix = $this->group ? $this->group . '.' : '';

            $redis->sAdd($prefix . 'queue_stats:' . $pool . ':workers', $workerName);

            $workerKey = $prefix . 'queue_stats:worker:' . $workerName;
            $redis->hMSet($workerKey, [
                'pool' => $pool,
                'status' => 'running',
                'last_heartbeat' => (string) time(),
            ]);
            $redis->expire($workerKey, self::WORKER_TTL);

            $this->registeredWorkers[$workerName] = $pool;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[QueueStats] Failed to register worker [%s]: %s', $workerName, $e->getMessage()));
        }
    }

    private function startHeartbeat(): void
    {
        $interval = 30000; // 30 seconds — half the 60s inactive threshold
        \Swoole\Timer::tick($interval, function () {
            $pools = $this->config->get('async_queue.pools', []);
            foreach (array_keys($pools) as $pool) {
                if (! $this->isRedisPool($pool)) {
                    continue;
                }

                try {
                    $this->registerPool($pool);
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf('[QueueStats] Heartbeat failed for pool [%s]: %s', $pool, $e->getMessage()));
                }
            }

            // Refresh worker TTLs
            foreach ($this->registeredWorkers as $workerName => $pool) {
                try {
                    $redis = $this->getRedisForPool($pool);
                    $prefix = $this->group ? $this->group . '.' : '';
                    $workerKey = $prefix . 'queue_stats:worker:' . $workerName;

                    $redis->hSet($workerKey, 'last_heartbeat', (string) time());
                    $redis->expire($workerKey, self::WORKER_TTL);
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf('[QueueStats] Worker heartbeat failed [%s]: %s', $workerName, $e->getMessage()));
                }
            }
        });
    }

    private function getRedisForPool(string $pool): RedisProxy
    {
        if (! isset($this->poolRedisMap[$pool])) {
            $redisPool = $this->config->get("async_queue.pools.{$pool}.redis.pool", 'default');
            $this->poolRedisMap[$pool] = $this->redisFactory->get($redisPool);
        }

        return $this->poolRedisMap[$pool];
    }

    private function getChannelForPool(string $pool): string
    {
        if (! isset($this->poolChannelMap[$pool])) {
            $this->poolChannelMap[$pool] = $this->config->get("async_queue.pools.{$pool}.channel", 'queue');
        }

        return $this->poolChannelMap[$pool];
    }

    private function getStatsKey(string $pool): string
    {
        $prefix = $this->group ? $this->group . '.' : '';

        return $prefix . 'queue_stats:' . $pool;
    }

    private function isRedisPool(string $pool): bool
    {
        $driver = $this->config->get("async_queue.pools.{$pool}.driver");

        return $driver === RedisDriver::class || is_subclass_of($driver, RedisDriver::class);
    }

    /**
     * Extract millisecond timestamp from UUID v7 (first 48 bits).
     */
    private function extractUuidV7Timestamp(string $uuid): float
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7/i', $uuid)) {
            return 0.0;
        }

        $hex = str_replace('-', '', substr($uuid, 0, 13));
        $ms = hexdec($hex);

        return $ms > 0 ? (float) $ms : 0.0;
    }
}
