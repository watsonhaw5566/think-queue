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
use think\queue\Job;

class Sync extends Job
{
    protected string $job;

    public function __construct(App $app, string $job, string $connection, string $queue)
    {
        $this->app        = $app;
        $this->connection = $connection;
        $this->queue      = $queue;
        $this->job        = $job;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function getRawBody(): string
    {
        return $this->job;
    }

    public function getJobId(): string
    {
        return '';
    }

    public function getQueue(): string
    {
        return 'sync';
    }
}