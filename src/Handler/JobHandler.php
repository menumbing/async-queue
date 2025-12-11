<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Handler;

use Hyperf\AsyncQueue\Enum\Result;
use Hyperf\AsyncQueue\Event\AfterHandle;
use Hyperf\AsyncQueue\Event\BeforeHandle;
use Hyperf\AsyncQueue\Event\FailedHandle;
use Hyperf\AsyncQueue\Event\RetryHandle;
use Hyperf\AsyncQueue\Exception\JobHandlingException;
use Hyperf\AsyncQueue\MessageInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class JobHandler
{
    protected ?EventDispatcherInterface $event = null;

    public function __construct(ContainerInterface $container)
    {
        $this->event = $container->get(EventDispatcherInterface::class);
    }

    public function handle(MessageInterface $message): Result
    {
        try {
            $this->event?->dispatch(new BeforeHandle($message, $message->getPool()));
            $message->job()->handle();
            $this->event?->dispatch(new AfterHandle($message, $message->getPool()));

            return Result::ACK;
        } catch (\Throwable $e) {
            if ($message->attempts()) {
                $this->event?->dispatch(new RetryHandle($message, $e, $message->getPool()));

                throw new JobHandlingException(Result::NACK, $e);
            }

            $this->fail($message, $e);

            throw new JobHandlingException(Result::DROP, $e);
        }
    }

    protected function fail(MessageInterface $message, \Throwable $throwable): void
    {
        $this->event?->dispatch(new FailedHandle($message, $throwable, $message->getPool()));
        $message->job()->fail($throwable);
    }
}
