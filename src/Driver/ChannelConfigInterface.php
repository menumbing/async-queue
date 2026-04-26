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
namespace Hyperf\AsyncQueue\Driver;

interface ChannelConfigInterface
{
    public function getChannel(): string;

    public function get(string $queue): string;

    public function getWaiting(): string;

    public function getReserved(): string;

    public function getTimeout(): string;

    public function getDelayed(): string;

    public function getFailed(): string;
}
