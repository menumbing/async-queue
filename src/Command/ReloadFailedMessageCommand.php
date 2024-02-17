<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Command;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverFactoryInterface;
use Hyperf\Command\Command;
use Menumbing\Contract\AsyncQueue\FailedQueueRecorderInterface;
use Psr\Container\ContainerInterface;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class ReloadFailedMessageCommand extends Command
{
    protected string $description = 'Reload specific failed message into waiting queue.';

    protected ?string $signature = 'queue:reload {id : The failed message id}';

    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $messageId = $this->argument('id');

        $failedRecorder = $this->container->get(FailedQueueRecorderInterface::class);

        if (null === $failedMessage = $failedRecorder->find($messageId)) {
            $this->error(sprintf('Failed message with id "%s" is not found.', $messageId));

            return;
        }

        $factory = $this->container->get(DriverFactoryInterface::class);
        $driver = $factory->get($failedMessage->pool);

        $driver->reload($failedMessage->payload);
        $failedRecorder->forget($failedMessage->id);

        $this->info(sprintf('Reload failed message with id "%s" into waiting queue.', $failedMessage->id));
    }
}
