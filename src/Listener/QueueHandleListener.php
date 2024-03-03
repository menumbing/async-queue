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
namespace Hyperf\AsyncQueue\Listener;

use Hyperf\AsyncQueue\AnnotationJob;
use Hyperf\AsyncQueue\Event\AfterHandle;
use Hyperf\AsyncQueue\Event\BeforeHandle;
use Hyperf\AsyncQueue\Event\Event;
use Hyperf\AsyncQueue\Event\FailedHandle;
use Hyperf\AsyncQueue\Event\RetryHandle;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Psr\Container\ContainerInterface;

class QueueHandleListener implements ListenerInterface
{
    protected StdoutLoggerInterface $logger;

    protected array $debugs = [];

    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->debugs = $container->get(ConfigInterface::class)->get('async_queue.debug', [
            'before' => false,
            'after' => false,
            'failed' => false,
            'retry' => false,
        ]);
    }

    public function listen(): array
    {
        return [
            AfterHandle::class,
            BeforeHandle::class,
            FailedHandle::class,
            RetryHandle::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof Event && $event->getMessage()->job()) {
            $job = $event->getMessage()->job();
            $jobClass = get_class($job);

            if ($job instanceof AnnotationJob) {
                $jobClass = sprintf('Job[%s@%s] on pool [%s]', $job->class, $job->method, $event->getPool());
            }

            $date = date('Y-m-d H:i:s');

            if ($event instanceof BeforeHandle && $this->isEnableDebug('before')) {
                $this->logger->info(sprintf('[%s] Processing %s.', $date, $jobClass));

                return;
            }

            if ($event instanceof AfterHandle && $this->isEnableDebug('after')) {
                $this->logger->info(sprintf('[%s] Processed %s.', $date, $jobClass));

                return;
            }

            if ($event instanceof FailedHandle && $this->isEnableDebug('failed')) {
                $this->logger->error(sprintf('[%s] Failed %s.', $date, $jobClass));
                $this->logger->error( $event->getThrowable()->getMessage());

                return;
            }

            if ($event instanceof RetryHandle && $this->isEnableDebug('retry')) {
                $this->logger->warning(sprintf('[%s] Retried %s.', $date, $jobClass));
            }
        }
    }

    protected function isEnableDebug(string $debug): bool
    {
        return $this->debugs[$debug] ?? false;
    }
}
