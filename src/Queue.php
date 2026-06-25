<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think;

use think\queue\Connector;
use think\queue\connector\Database;
use think\queue\connector\Redis;

/**
 * Class Queue
 * @package think\queue
 *
 * @mixin Database
 * @mixin Redis
 *
 * @property-read App    $app
 * @property-read Config $config
 * @property-read Event  $event
 */
class Queue extends Manager
{
    protected string $namespace = '\\think\\queue\\connector\\';

    protected function resolveType(string $name): string
    {
        return (string) $this->app->config->get("queue.connections.{$name}.type", 'sync');
    }

    protected function resolveConfig(string $name): mixed
    {
        return $this->app->config->get("queue.connections.{$name}");
    }

    protected function createDriver(string $name): Connector
    {
        /** @var Connector $driver */
        $driver = parent::createDriver($name);

        if (!$driver instanceof Connector) {
            throw new \RuntimeException(
                sprintf('Driver "%s" must be an instance of %s.', $name, Connector::class)
            );
        }

        return $driver->setApp($this->app)->setConnection($name);
    }

    public function connection(?string $name = null): Connector
    {
        return $this->driver($name);
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->config->get('queue.default');
    }
}