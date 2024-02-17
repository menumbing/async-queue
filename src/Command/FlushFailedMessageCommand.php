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
namespace Hyperf\AsyncQueue\Command;

use Hyperf\Command\Command as HyperfCommand;
use Menumbing\Contract\AsyncQueue\FailedQueueRecorderInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

class FlushFailedMessageCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('queue:flush');
    }

    public function handle(): void
    {
        $pool = $this->input->getOption('pool');

        $recorder = $this->container->get(FailedQueueRecorderInterface::class);

        $num = $recorder->flush($pool);

        if (!$num) {
            $this->error('There is no failed messages to flush.');

            return;
        }

        $this->info(sprintf('Flush %d messages from failed queue.', $num));
    }

    protected function configure(): void
    {
        $this->setDescription('Delete all message from failed queue.');
        $this->addOption('pool', 'P', InputArgument::OPTIONAL, 'The pool of queue.', null);
    }
}
