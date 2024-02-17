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
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

class InfoCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('queue:info');
    }

    public function handle()
    {
        $pool = $this->input->getArgument('pool');
        $factory = $this->container->get(DriverFactoryInterface::class);
        $driver = $factory->get($pool);

        $info = $driver->info();
        foreach ($info as $key => $count) {
            $this->info(sprintf('%s count is %d', $key, $count));
        }
    }

    protected function configure()
    {
        $this->setDescription('Count all messages from the queue.');
        $this->addArgument('pool', InputArgument::OPTIONAL, 'The pool of queue.', 'default');
    }
}
