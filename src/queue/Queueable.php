<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\queue;

use DateTimeInterface;

/**
 * 为任务类提供链式配置能力。
 *
 * 典型用法：
 * ```php
 * $job = (new SendEmail)->onConnection('redis')->onQueue('high')->delay(60);
 * \think\facade\Queue::push($job);
 * ```
 */
trait Queueable
{
    /**
     * 任务使用的连接名（null 表示使用默认连接）。
     */
    public ?string $connection = null;

    /**
     * 任务使用的队列名（null 表示使用默认队列）。
     */
    public ?string $queue = null;

    /**
     * 任务延迟时间（null 表示不延迟；数字为秒数；也可传入 DateTimeInterface 表示绝对时间）。
     *
     * @var \DateTimeInterface|int|null
     */
    public $delay = null;

    /**
     * 设置任务使用的连接名。
     *
     * @return $this
     */
    public function onConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * 设置任务使用的队列名。
     *
     * @return $this
     */
    public function onQueue(?string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * 设置任务的延迟时间。
     *
     * @param \DateTimeInterface|int|null $delay 秒数（int）或 绝对时间（DateTimeInterface）
     * @return $this
     */
    public function delay($delay): static
    {
        $this->delay = $delay;

        return $this;
    }
}