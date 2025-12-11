<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Command;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverFactoryInterface;
use Hyperf\AsyncQueue\Driver\DriverManager;
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

    public function __construct(
        protected FailedQueueRecorderInterface $failedRecorder,
        protected DriverManager $driverManager,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $messageId = $this->argument('id');

        if (null === $failedMessage = $this->failedRecorder->find($messageId)) {
            $this->error(sprintf('Failed message with id "%s" is not found.', $messageId));

            return;
        }

        $this->driverManager->reload($failedMessage->payload, $failedMessage->pool);
        $this->failedRecorder->forget($failedMessage->id);

        $this->info(sprintf('Reload failed message with id "%s" into waiting queue.', $failedMessage->id));
    }
}
