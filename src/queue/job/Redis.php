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

namespace think\queue\job;

use think\App;
use think\queue\connector\Redis as RedisQueue;
use think\queue\Job;

class Redis extends Job
{
    protected RedisQueue $redis;

    protected string $job;

    protected string $reserved;

    public function __construct(
        App $app,
        RedisQueue $redis,
        string $job,
        string $reserved,
        string $connection,
        string $queue,
    ) {
        $this->app        = $app;
        $this->job        = $job;
        $this->queue      = $queue;
        $this->connection = $connection;
        $this->redis      = $redis;
        $this->reserved   = $reserved;
    }

    public function attempts(): int
    {
        $attempts = $this->payload('attempts', 0);

        return (int) $attempts + 1;
    }

    public function getRawBody(): string
    {
        return $this->job;
    }

    public function delete(): void
    {
        parent::delete();

        $this->redis->deleteReserved($this->queue, $this);
    }

    public function release(int $delay = 0): void
    {
        parent::release($delay);

        $this->redis->deleteAndRelease($this->queue, $this, $delay);
    }

    public function getJobId(): mixed
    {
        return $this->payload('id');
    }

    public function getReservedJob(): string
    {
        return $this->reserved;
    }
}