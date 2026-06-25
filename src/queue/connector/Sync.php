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
use think\queue\Job;
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

    public function pop(?string $queue = null): ?Job
    {
        return null;
    }

    protected function resolveJob(string $payload, ?string $queue): Job
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
        // Sync connector executes jobs immediately within the current process;
        // there is no persistent queue or background worker to honor a delay.
        // Therefore the $delay parameter cannot be meaningfully applied here.
        // Switch to the Redis or Database connector in production when you
        // genuinely need deferred execution.
        $delayDesc = $delay instanceof \DateTimeInterface
            ? $delay->format('Y-m-d H:i:s')
            : $delay . 's';

        $jobName = is_object($job) ? get_class($job) : (string) $job;

        @trigger_error(
            sprintf(
                '[queue] sync connector ignores delay (%s) for job %s; executing immediately. '
                . 'Use the Redis or Database connector for genuine deferred execution.',
                $delayDesc,
                $jobName
            )
        );

        return $this->push($job, $data, $queue);
    }
}