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
use think\queue\connector\Database as DatabaseQueue;
use think\queue\Job;

class Database extends Job
{
    protected DatabaseQueue $database;

    /** @var object{id: mixed, payload: string, attempts: int} */
    protected object $job;

    /**
     * @param object{id: mixed, payload: string, attempts: int} $job
     */
    public function __construct(App $app, DatabaseQueue $database, object $job, string $connection, string $queue)
    {
        $this->app        = $app;
        $this->job        = $job;
        $this->queue      = $queue;
        $this->database   = $database;
        $this->connection = $connection;
    }

    public function delete(): void
    {
        parent::delete();
        $this->database->deleteReserved($this->job->id);
    }

    public function release(int $delay = 0): void
    {
        parent::release($delay);

        $this->delete();

        $this->database->release($this->queue, $this->job, $delay);
    }

    public function attempts(): int
    {
        return (int) ($this->job->attempts ?? 0);
    }

    public function getRawBody(): string
    {
        return (string) $this->job->payload;
    }

    public function getJobId(): mixed
    {
        return $this->job->id;
    }
}