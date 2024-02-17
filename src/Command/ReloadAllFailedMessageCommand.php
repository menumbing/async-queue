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

use Hyperf\AsyncQueue\Driver\DriverFactoryInterface;
use Hyperf\Command\Command as HyperfCommand;
use Menumbing\Contract\AsyncQueue\FailedQueueRecorderInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ReloadAllFailedMessageCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('queue:reload-all');
    }

    public function handle(): void
    {
        $pool = $this->input->getOption('pool');

        $failedRecorder = $this->container->get(FailedQueueRecorderInterface::class);
        $num = $failedRecorder->count($pool);

        if ($num <= 0) {
            $this->error('There is no failed messages.');

            return;
        }

        $progressBar = $this->output->createProgressBar($num);
        $progressBar->setMessage('Reloading messages into queue.');
        $progressBar->start();

        $factory = $this->container->get(DriverFactoryInterface::class);

        foreach ($failedRecorder->all($pool) as $item) {
            $driver = $factory->get($item->pool);

            $driver->reload($item->payload);
            $failedRecorder->forget($item->id);
            $progressBar->advance();
        }

        $progressBar->finish();

        $this->output->writeln('');

        $this->info(sprintf('Reload %d failed messages into waiting queue.', $num));
    }

    protected function configure(): void
    {
        $this->setDescription('Reload all failed message into waiting queue.');
        $this->addOption('pool', 'P', InputOption::VALUE_OPTIONAL, 'The pool of queue.', null);
    }
}
