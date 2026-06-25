<?php

namespace think\facade;

use think\Facade;

/**
 * Queue 门面类。
 *
 * 静态代理到 {@see \think\Queue}，并经由它路由到当前默认连接的
 * {@see \think\queue\Connector} 实例上。
 *
 * ```php
 * // 推送任务到默认连接/默认队列
 * \think\facade\Queue::push(new SendEmail);
 *
 * // 延迟 60 秒推送
 * \think\facade\Queue::later(60, new SendEmail);
 *
 * // 切换连接
 * \think\facade\Queue::connection('redis')->push(new SendEmail);
 * ```
 *
 * @mixin \think\Queue
 * @mixin \think\queue\Connector
 *
 * @method static \think\queue\Connector connection(?string $name = null)
 * @method static \think\queue\Connector driver(?string $name = null)
 * @method static string getDefaultDriver()
 * @method static int size(?string $queue = null)
 * @method static mixed push(object|string $job, mixed $data = '', ?string $queue = null)
 * @method static mixed pushOn(string $queue, object|string $job, mixed $data = '')
 * @method static mixed pushRaw(string $payload, ?string $queue = null, array $options = [])
 * @method static mixed later(\DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null)
 * @method static mixed laterOn(string $queue, \DateTimeInterface|int $delay, object|string $job, mixed $data = '')
 * @method static void bulk(iterable $jobs, mixed $data = '', ?string $queue = null)
 * @method static \think\queue\Job|null pop(?string $queue = null)
 */
class Queue extends Facade
{
    protected static function getFacadeClass(): string
    {
        return 'queue';
    }
}