<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Failed;

use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

use function Hyperf\Support\make;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class FailedQueueRecorderFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->get(ConfigInterface::class);
        $driverClass = $config->get('async_queue.failed.recorder', NullFailedQueueRecorder::class);
        $options = $config->get('async_queue.failed.options', []);

        return make($driverClass, ['container' => $container, 'options' => $options]);
    }
}
