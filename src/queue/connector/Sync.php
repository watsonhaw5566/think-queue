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

namespace think\queue\connector;

use Exception;
use think\queue\Connector;
use think\queue\event\JobFailed;
use think\queue\event\JobProcessed;
use think\queue\event\JobProcessing;
use think\queue\job\Sync as SyncJob;
use Throwable;

class Sync extends Connector
{
    public function size(?string $queue = null): int
    {
        return 0;
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): int
    {
        $payload  = $this->createPayload($job, $data);
        $queueJob = $this->resolveJob($payload, $queue);

        try {
            $this->triggerEvent(new JobProcessing($this->connection, $queueJob));

            $queueJob->fire();

            $this->triggerEvent(new JobProcessed($this->connection, $queueJob));
        } catch (Exception | Throwable $e) {
            $this->triggerEvent(new JobFailed($this->connection, $queueJob, $e));

            throw $e;
        }

        return 0;
    }

    protected function triggerEvent(object $event): void
    {
        $this->app->event->trigger($event);
    }

    public function pop(?string $queue = null): ?\think\queue\Job
    {
        return null;
    }

    protected function resolveJob(string $payload, ?string $queue): \think\queue\Job
    {
        return new SyncJob($this->app, $payload, $this->connection, $queue);
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        // Sync connector has no queue; this method is required by the contract
        // but pushing raw payloads in a synchronous context is a no-op.
        return null;
    }

    public function later(\DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): int
    {
        return $this->push($job, $data, $queue);
    }
}