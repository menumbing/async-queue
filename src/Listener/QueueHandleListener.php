<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Listener;

use Hyperf\AsyncQueue\AnnotationJob;
use Hyperf\AsyncQueue\Event\AfterHandle;
use Hyperf\AsyncQueue\Event\AfterPush;
use Hyperf\AsyncQueue\Event\BeforeHandle;
use Hyperf\AsyncQueue\Event\BeforePush;
use Hyperf\AsyncQueue\Event\Event;
use Hyperf\AsyncQueue\Event\FailedHandle;
use Hyperf\AsyncQueue\Event\PushEvent;
use Hyperf\AsyncQueue\Event\PushFailed;
use Hyperf\AsyncQueue\Event\RetryHandle;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Contract\ListenerInterface;

class QueueHandleListener implements ListenerInterface
{
    protected array $debugs = [];

    public function __construct(
        protected StdoutLoggerInterface $logger,
        ConfigInterface $config,
    ) {
        $this->debugs = $config->get('async_queue.debug', [
            'before' => false,
            'after' => false,
            'failed' => false,
            'retry' => false,
            'before_push' => false,
            'after_push' => false,
            'push_failed' => false,
        ]);
    }

    public function listen(): array
    {
        return [
            BeforeHandle::class,
            AfterHandle::class,
            FailedHandle::class,
            RetryHandle::class,
            BeforePush::class,
            AfterPush::class,
            PushFailed::class,
        ];
    }

    public function process(object $event): void
    {
        match (true) {
            $event instanceof BeforeHandle => $this->logHandleEvent('Processing', 'before', $event),
            $event instanceof AfterHandle => $this->logHandleEvent('Processed', 'after', $event),
            $event instanceof FailedHandle => $this->logFailedEvent($event),
            $event instanceof RetryHandle => $this->logRetryEvent($event),
            $event instanceof BeforePush => $this->logPushEvent('Pushing', 'before_push', $event),
            $event instanceof AfterPush => $this->logPushEvent('Pushed', 'after_push', $event),
            $event instanceof PushFailed => $this->logPushFailedEvent($event),
            default => null,
        };
    }

    private function logHandleEvent(string $status, string $debugKey, Event $event): void
    {
        if (!$this->isEnableDebug($debugKey)) {
            return;
        }

        $message = $event->getMessage();

        $this->logger->info(
            message: 'AsyncQueue [{status}] pool={pool} job={job} message_id={message_id} attempts={attempts}',
            context: [
                'status' => $status,
                'pool' => $event->getPool(),
                'job' => $this->resolveJobClass($message->job()),
                'message_id' => $message->getId(),
                'attempts' => $message->getAttempts(),
            ],
        );
    }

    private function logFailedEvent(FailedHandle $event): void
    {
        if (!$this->isEnableDebug('failed')) {
            return;
        }

        $message = $event->getMessage();

        $this->logger->error(
            message: 'AsyncQueue [Failed] pool={pool} job={job} message_id={message_id} attempts={attempts} error={error}',
            context: [
                'pool' => $event->getPool(),
                'job' => $this->resolveJobClass($message->job()),
                'message_id' => $message->getId(),
                'attempts' => $message->getAttempts(),
                'error' => $event->getThrowable()->getMessage(),
                'exception' => (string) $event->getThrowable(),
            ],
        );
    }

    private function logRetryEvent(RetryHandle $event): void
    {
        if (!$this->isEnableDebug('retry')) {
            return;
        }

        $message = $event->getMessage();

        $this->logger->warning(
            message: 'AsyncQueue [Retrying] pool={pool} job={job} message_id={message_id} attempts={attempts} error={error}',
            context: [
                'pool' => $event->getPool(),
                'job' => $this->resolveJobClass($message->job()),
                'message_id' => $message->getId(),
                'attempts' => $message->getAttempts(),
                'error' => $event->getThrowable()->getMessage(),
                'exception' => (string) $event->getThrowable(),
            ],
        );
    }

    private function logPushEvent(string $status, string $debugKey, PushEvent $event): void
    {
        if (!$this->isEnableDebug($debugKey)) {
            return;
        }

        $context = [
            'status' => $status,
            'pool' => $event->pool,
            'job' => $this->resolveJobClass($event->job),
            'delay' => $event->delay,
        ];

        if (null !== $event->endTime) {
            $context['duration_ms'] = round(($event->endTime - $event->startTime) * 1000, 2);
        }

        $this->logger->info(
            message: 'AsyncQueue [{status}] pool={pool} job={job} delay={delay}' . (isset($context['duration_ms']) ? ' duration={duration_ms}ms' : ''),
            context: $context,
        );
    }

    private function logPushFailedEvent(PushFailed $event): void
    {
        if (!$this->isEnableDebug('push_failed')) {
            return;
        }

        $context = [
            'pool' => $event->pool,
            'job' => $this->resolveJobClass($event->job),
            'delay' => $event->delay,
            'error' => $event->throwable->getMessage(),
            'exception' => (string) $event->throwable,
        ];

        if (null !== $event->endTime) {
            $context['duration_ms'] = round(($event->endTime - $event->startTime) * 1000, 2);
        }

        $this->logger->error(
            message: 'AsyncQueue [Push failed] pool={pool} job={job} delay={delay} error={error}',
            context: $context,
        );
    }

    private function resolveJobClass(JobInterface $job): string
    {
        if ($job instanceof AnnotationJob) {
            return sprintf('Job[%s@%s]', $job->class, $job->method);
        }

        return get_class($job);
    }

    protected function isEnableDebug(string $debug): bool
    {
        return $this->debugs[$debug] ?? false;
    }
}
