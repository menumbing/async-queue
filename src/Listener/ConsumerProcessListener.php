<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Listener;

use Hyperf\AsyncQueue\Event\ConsumeError;
use Hyperf\AsyncQueue\Process\ConsumerProcess;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Process\Event\AfterProcessHandle;
use Hyperf\Process\Event\BeforeProcessHandle;

class ConsumerProcessListener implements ListenerInterface
{
    public function __construct(
        private StdoutLoggerInterface $logger,
    ) {
    }

    public function listen(): array
    {
        return [
            BeforeProcessHandle::class,
            AfterProcessHandle::class,
            ConsumeError::class,
        ];
    }

    public function process(object $event): void
    {
        match (true) {
            $event instanceof BeforeProcessHandle => $this->logProcessStart($event),
            $event instanceof AfterProcessHandle => $this->logProcessStop($event),
            $event instanceof ConsumeError => $this->logConsumeError($event),
            default => null,
        };
    }

    private function logProcessStart(BeforeProcessHandle $event): void
    {
        if (!$this->isConsumerProcess($event)) {
            return;
        }

        $this->logger->info(
            message: 'AsyncQueue [Consumer started] pool={pool} worker={worker} pid={pid}',
            context: [
                'pool' => $event->process->getQueue(),
                'worker' => $event->process->name . '.' . $event->index,
                'pid' => getmypid(),
            ],
        );
    }

    private function logProcessStop(AfterProcessHandle $event): void
    {
        if (!$this->isConsumerProcess($event)) {
            return;
        }

        $this->logger->info(
            message: 'AsyncQueue [Consumer stopped] pool={pool} worker={worker} pid={pid}',
            context: [
                'pool' => $event->process->getQueue(),
                'worker' => $event->process->name . '.' . $event->index,
                'pid' => getmypid(),
            ],
        );
    }

    private function logConsumeError(ConsumeError $event): void
    {
        $this->logger->error(
            message: 'AsyncQueue [Consumer error] pool={pool} error={error}',
            context: [
                'pool' => $event->pool,
                'error' => $event->throwable->getMessage(),
                'exception' => (string) $event->throwable,
            ],
        );
    }

    private function isConsumerProcess(object $event): bool
    {
        return $event->process instanceof ConsumerProcess;
    }
}
