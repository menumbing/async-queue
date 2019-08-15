<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\AsyncQueue\Aspect;

use Hyperf\AsyncQueue\Annotation\AsyncQueue;
use Hyperf\AsyncQueue\AnnotationJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Environment;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Psr\Container\ContainerInterface;

/**
 * @Aspect
 */
class AsyncQueueAspect extends AbstractAspect
{
    public $annotations = [
        AsyncQueue::class,
    ];

    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $env = $this->container->get(Environment::class);
        if ($env->isAsyncQueue()) {
            $proceedingJoinPoint->process();
            return;
        }

        $class = $proceedingJoinPoint->className;
        $method = $proceedingJoinPoint->methodName;
        $arguments = $proceedingJoinPoint->getArguments();
        $pool = 'default';
        $delay = 0;

        $metadata = $proceedingJoinPoint->getAnnotationMetadata();
        /** @var AsyncQueue $annotation */
        $annotation = $metadata->method[AsyncQueue::class] ?? $metadata->class[AsyncQueue::class] ?? null;
        if ($annotation instanceof AsyncQueue) {
            $pool = $annotation->pool;
            $delay = $annotation->delay;
        }

        $factory = $this->container->get(DriverFactory::class);
        $driver = $factory->get($pool);

        $driver->push(new AnnotationJob($class, $method, $arguments), $delay);
    }
}