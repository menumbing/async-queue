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

use Hyperf\AsyncQueue\Driver\DriverManager;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputArgument;

class InfoCommand extends HyperfCommand
{
    public function __construct(protected DriverManager $driverManager)
    {
        parent::__construct('queue:info');
    }

    public function handle()
    {
        $info = $this->driverManager->info($this->input->getArgument('pool'));
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
