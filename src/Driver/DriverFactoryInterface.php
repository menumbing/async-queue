<?php

declare(strict_types=1);

namespace Hyperf\AsyncQueue\Driver;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
interface DriverFactoryInterface
{
    public function __get($name): DriverInterface;

    public function get(string $name): DriverInterface;

    public function getConfig($name): array;
}
