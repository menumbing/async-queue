<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Listener;

use Hyperf\AsyncQueue\Process\ConsumerProcess;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Hyperf\Process\ProcessManager;
use Hyperf\Server\Event\MainCoroutineServerStart;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
class RegisterProcessListener implements ListenerInterface
{
    public function __construct(protected ContainerInterface $container, protected ConfigInterface $config)
    {
    }

    public function listen(): array
    {
        return [
            BeforeMainServerStart::class,
            MainCoroutineServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        foreach ($this->config->get('async_queue.pools', []) as $poolName => $options) {
            if (!($options['auto_register_process'] ?? false)) {
                continue;
            }

            ProcessManager::register($this->createProcess($poolName));
        }
    }

    protected function createProcess(string $poolName): ConsumerProcess
    {
        return new class($this->container, $poolName) extends ConsumerProcess {
            public function __construct(ContainerInterface $container, string $poolName)
            {
                $this->queue = $poolName;

                parent::__construct($container);
            }
        };
    }
}
