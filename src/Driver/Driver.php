<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\AsyncQueue\Driver;

use Hyperf\AsyncQueue\Event\AfterHandle;
use Hyperf\AsyncQueue\Event\BeforeHandle;
use Hyperf\AsyncQueue\Event\FailedHandle;
use Hyperf\AsyncQueue\Event\QueueLength;
use Hyperf\AsyncQueue\Event\RetryHandle;
use Hyperf\AsyncQueue\MessageInterface;
use Hyperf\Codec\Packer\PhpSerializerPacker;
use Hyperf\Collection\Arr;
use Hyperf\Contract\PackerInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Concurrent;
use Hyperf\Process\ProcessManager;
use Menumbing\Contract\AsyncQueue\FailedQueueRecorderInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function Hyperf\Coroutine\parallel;

abstract class Driver implements DriverInterface
{
    protected PackerInterface $packer;

    protected ?EventDispatcherInterface $event = null;

    protected FailedQueueRecorderInterface $failedQueueRecorder;

    protected ?Concurrent $concurrent = null;

    protected int $lengthCheckCount = 500;

    protected string $pool;

    public function __construct(protected ContainerInterface $container, protected array $config)
    {
        $this->packer = $container->get($config['packer'] ?? PhpSerializerPacker::class);
        $this->event = $container->get(EventDispatcherInterface::class);
        $this->failedQueueRecorder = $container->get(FailedQueueRecorderInterface::class);
        $this->pool = $this->config['pool'];

        $concurrentLimit = $config['concurrent']['limit'] ?? null;
        if ($concurrentLimit && is_numeric($concurrentLimit)) {
            $this->concurrent = new Concurrent((int) $concurrentLimit);
        }
    }

    public function consume(): void
    {
        $messageCount = 0;
        $maxMessages = Arr::get($this->config, 'max_messages', 0);

        while (ProcessManager::isRunning()) {
            try {
                /** @var MessageInterface $message */
                [$data, $message] = $this->pop();

                if ($data === false) {
                    continue;
                }

                $callback = $this->getCallback($data, $message);

                if ($this->concurrent) {
                    $this->concurrent->create($callback);
                } else {
                    parallel([$callback]);
                }

                if ($messageCount % $this->lengthCheckCount === 0) {
                    $this->checkQueueLength();
                }

                if ($maxMessages > 0 && $messageCount >= $maxMessages) {
                    break;
                }
            } catch (Throwable $exception) {
                $logger = $this->container->get(StdoutLoggerInterface::class);
                $logger->error((string) $exception);
            } finally {
                ++$messageCount;
            }
        }
    }

    public function recordFailedMessage(string $id, mixed $data, Throwable $exception): void
    {
        $this->failedQueueRecorder->record($id, $this->pool, (string) $data, $exception);
    }

    protected function checkQueueLength(): void
    {
        $info = $this->info();
        foreach ($info as $key => $value) {
            $this->event?->dispatch(new QueueLength($this, $key, $value));
        }
    }

    /**
     * @param mixed $data
     * @param MessageInterface $message
     */
    protected function getCallback($data, $message): callable
    {
        return function () use ($data, $message) {
            try {
                if ($message instanceof MessageInterface) {
                    $this->event?->dispatch(new BeforeHandle($message, $this->pool));
                    $message->job()->handle();
                    $this->event?->dispatch(new AfterHandle($message, $this->pool));
                }

                $this->ack($data);
            } catch (Throwable $ex) {
                if (isset($message, $data)) {
                    if ($message->attempts() && $this->remove($data)) {
                        $this->event?->dispatch(new RetryHandle($message, $ex, $this->pool));
                        $this->retry($message);
                    } else {
                        $this->event?->dispatch(new FailedHandle($message, $ex, $this->pool));
                        $this->fail($data);
                        $this->recordFailedMessage($message->getId(), $data, $ex);
                        $message->job()->fail($ex);
                    }
                }
            }
        };
    }

    /**
     * Handle a job again some seconds later.
     */
    abstract protected function retry(MessageInterface $message): bool;

    /**
     * Remove data from reserved queue.
     */
    abstract protected function remove(mixed $data): bool;
}
