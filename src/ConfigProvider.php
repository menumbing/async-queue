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
namespace Hyperf\AsyncQueue;

use Hyperf\AsyncQueue\Aspect\AsyncQueueAspect;
use Hyperf\AsyncQueue\Command\FlushFailedMessageCommand;
use Hyperf\AsyncQueue\Command\InfoCommand;
use Hyperf\AsyncQueue\Command\ReloadAllFailedMessageCommand;
use Hyperf\AsyncQueue\Command\ReloadFailedMessageCommand;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverFactoryInterface;
use Hyperf\AsyncQueue\Driver\DriverManager;
use Hyperf\AsyncQueue\Failed\FailedQueueRecorderFactory;
use Hyperf\AsyncQueue\Handler\JobHandler;
use Hyperf\AsyncQueue\Listener\QueueHandleListener;
use Hyperf\AsyncQueue\Listener\QueueLengthListener;
use Hyperf\AsyncQueue\Listener\RegisterProcessListener;
use Hyperf\AsyncQueue\Listener\ReloadChannelListener;
use Menumbing\Contract\AsyncQueue\FailedQueueRecorderInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'aspects' => [
                AsyncQueueAspect::class,
            ],
            'dependencies' => [
                DriverFactoryInterface::class => DriverFactory::class,
                FailedQueueRecorderInterface::class => FailedQueueRecorderFactory::class,
                DriverManager::class => DriverManager::class,
                JobHandler::class => JobHandler::class,
            ],
            'listeners' => [
                RegisterProcessListener::class => 99,
                QueueHandleListener::class,
                ReloadChannelListener::class,
            ],
            'commands' => [
                FlushFailedMessageCommand::class,
                InfoCommand::class,
                ReloadAllFailedMessageCommand::class,
                ReloadFailedMessageCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for async queue.',
                    'source' => __DIR__ . '/../publish/async_queue.php',
                    'destination' => BASE_PATH . '/config/autoload/async_queue.php',
                ],
                [
                    'id' => 'migration:failed-messages',
                    'description' => 'The failed messages migration.',
                    'source' => __DIR__ . '/../publish/migrations/2024_02_16_192823_create_failed_messages_table.php',
                    'destination' => BASE_PATH . '/migrations/2024_02_16_192823_create_failed_messages_table.php',
                ],
            ],
        ];
    }
}
