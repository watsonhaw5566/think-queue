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

namespace think\queue;

use Carbon\Carbon;
use Exception;
use RuntimeException;
use think\Cache;
use think\Event;
use think\exception\Handle;
use think\Queue;
use think\queue\event\JobExceptionOccurred;
use think\queue\event\JobFailed;
use think\queue\event\JobProcessed;
use think\queue\event\JobProcessing;
use think\queue\event\WorkerStopping;
use think\queue\exception\MaxAttemptsExceededException;
use Throwable;

class Worker
{
    protected Event $event;

    protected Handle $handle;

    protected Queue $queue;

    protected ?Cache $cache;

    public bool $shouldQuit = false;

    public bool $paused = false;

    /**
     * 防止同一任务在一次执行中被 failJob() 重复调用。
     */
    private bool $failedMarkedForCurrentJob = false;

    public function __construct(Queue $queue, Event $event, Handle $handle, ?Cache $cache = null)
    {
        $this->queue  = $queue;
        $this->event  = $event;
        $this->handle = $handle;
        $this->cache  = $cache;
    }

    /**
     * @param string $connection
     * @param string $queue
     */
    public function daemon(
        string $connection,
        string $queue,
        int $delay = 0,
        int $sleep = 3,
        int $maxTries = 0,
        int $memory = 128,
        int $timeout = 60,
    ): void {
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        $lastRestart = $this->getTimestampOfLastQueueRestart();

        while (true) {
            // 处理暂停信号。
            while ($this->paused && !$this->shouldQuit) {
                $this->sleep(1);
            }

            $job = $this->getNextJob(
                $this->queue->connection($connection),
                $queue
            );

            if ($this->supportsAsyncSignals()) {
                $this->registerTimeoutHandler($job, $timeout);
            }

            if ($job !== null) {
                $this->failedMarkedForCurrentJob = false;
                $this->runJob($job, $connection, $maxTries, $delay);
            } else {
                $this->sleep($sleep);
            }

            $this->stopIfNecessary($job, $lastRestart, $memory);
        }
    }

    protected function stopIfNecessary(?Job $job, mixed $lastRestart, int $memory): void
    {
        if ($this->shouldQuit || $this->queueShouldRestart($lastRestart)) {
            $this->stop();
        } elseif ($this->memoryExceeded($memory)) {
            $this->stop(12);
        }
    }

    protected function queueShouldRestart(mixed $lastRestart): bool
    {
        return $this->getTimestampOfLastQueueRestart() != $lastRestart;
    }

    public function memoryExceeded(int $memoryLimit): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * 获取队列重启时间。
     */
    protected function getTimestampOfLastQueueRestart(): ?int
    {
        if ($this->cache !== null) {
            $value = $this->cache->get('think:queue:restart');

            return is_numeric($value) ? (int) $value : null;
        }

        return null;
    }

    /**
     * 注册超时处理：先尝试清理当前 job，再终止进程。
     */
    protected function registerTimeoutHandler(?Job $job, int $timeout): void
    {
        $worker = $this;

        pcntl_signal(SIGALRM, static function () use ($worker, $job, $timeout): void {
            // 给当前 job 一次失败回调的机会，同时触发事件。
            if ($job !== null && !$job->hasFailed() && !$job->isDeleted()) {
                try {
                    $worker->failJob(
                        (string) $job->getConnection(),
                        $job,
                        new MaxAttemptsExceededException(
                            sprintf(
                                'Job "%s" exceeded its timeout of %d seconds.',
                                $job->getName(),
                                $timeout
                            )
                        )
                    );
                } catch (Throwable $e) {
                    // ignore — we are inside a signal handler.
                }
            }

            $worker->kill(1);
        });

        pcntl_alarm(max($this->timeoutForJob($job, $timeout), 0));
    }

    public function stop(int $status = 0): void
    {
        $this->event->trigger(new WorkerStopping($status));

        exit($status);
    }

    public function kill(int $status = 0): void
    {
        $this->event->trigger(new WorkerStopping($status));

        if (extension_loaded('posix')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    protected function timeoutForJob(?Job $job, int $timeout): int
    {
        if ($job === null) {
            return $timeout;
        }

        $jobTimeout = $job->timeout();
        return $jobTimeout !== null ? (int) $jobTimeout : $timeout;
    }

    protected function supportsAsyncSignals(): bool
    {
        return extension_loaded('pcntl') && function_exists('pcntl_async_signals');
    }

    protected function listenForSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function (): void {
            $this->shouldQuit = true;
        });

        pcntl_signal(SIGINT, function (): void {
            $this->shouldQuit = true;
        });

        pcntl_signal(SIGUSR2, function (): void {
            $this->paused = true;
        });

        pcntl_signal(SIGCONT, function (): void {
            $this->paused = false;
        });
    }

    /**
     * 执行下个任务。
     *
     * @throws Exception
     */
    public function runNextJob(string $connection, string $queue, int $delay = 0, int $sleep = 3, int $maxTries = 0): void
    {
        $job = $this->getNextJob($this->queue->connection($connection), $queue);

        if ($job !== null) {
            $this->failedMarkedForCurrentJob = false;
            $this->runJob($job, $connection, $maxTries, $delay);
        } else {
            $this->sleep($sleep);
        }
    }

    /**
     * 执行任务 —— 捕获异常后上报，并确保失败事件被触发。
     */
    protected function runJob(Job $job, string $connection, int $maxTries, int $delay): void
    {
        try {
            $this->process($connection, $job, $maxTries, $delay);
        } catch (MaxAttemptsExceededException $e) {
            // 已经在 failJob() 中触发事件并上报 — 不重复触发。
            $this->handle->report($e);
        } catch (Exception | Throwable $e) {
            $this->handle->report($e);

            if (!$this->failedMarkedForCurrentJob && !$job->hasFailed()) {
                $this->markJobAsFailedIfWillExceedMaxAttempts($connection, $job, $maxTries, $e);
            }
        }
    }

    /**
     * 获取下个任务（支持逗号分隔的多队列）。
     */
    protected function getNextJob(Connector $connector, string $queue): ?Job
    {
        try {
            foreach (explode(',', $queue) as $queueName) {
                $queueName = trim($queueName);
                if ($queueName === '') {
                    continue;
                }

                $job = $connector->pop($queueName);
                if ($job !== null) {
                    return $job;
                }
            }
        } catch (Exception | Throwable $e) {
            $this->handle->report($e);
            $this->sleep(1);
        }

        return null;
    }

    /**
     * Process a given job from the queue.
     *
     * @throws Exception
     */
    public function process(string $connection, Job $job, int $maxTries = 0, int $delay = 0): void
    {
        try {
            $this->event->trigger(new JobProcessing($connection, $job));

            $this->markJobAsFailedIfAlreadyExceedsMaxAttempts(
                $connection,
                $job,
                $maxTries
            );

            $job->fire();

            $this->event->trigger(new JobProcessed($connection, $job));
        } catch (Exception | Throwable $e) {
            try {
                if (!$job->hasFailed()) {
                    $this->markJobAsFailedIfWillExceedMaxAttempts($connection, $job, $maxTries, $e);
                }

                $this->event->trigger(new JobExceptionOccurred($connection, $job, $e));
            } finally {
                if (!$job->isDeleted() && !$job->isReleased() && !$job->hasFailed()) {
                    $job->release($delay);
                }
            }

            throw $e;
        }
    }

    protected function markJobAsFailedIfAlreadyExceedsMaxAttempts(string $connection, Job $job, int $maxTries): void
    {
        $maxTries = $job->maxTries() !== null ? (int) $job->maxTries() : $maxTries;
        $timeoutAt = $job->timeoutAt();

        if ($timeoutAt !== null && Carbon::now()->getTimestamp() <= (int) $timeoutAt) {
            return;
        }

        if ($timeoutAt === null && (0 === $maxTries || $job->attempts() <= $maxTries)) {
            return;
        }

        $maxAttemptsExceededException = new MaxAttemptsExceededException(
            $job->getName() . ' has been attempted too many times or run too long. The job may have previously timed out.'
        );

        $this->failJob($connection, $job, $maxAttemptsExceededException);

        throw $maxAttemptsExceededException;
    }

    protected function markJobAsFailedIfWillExceedMaxAttempts(string $connection, Job $job, int $maxTries, Throwable $e): void
    {
        $maxTries = $job->maxTries() !== null ? (int) $job->maxTries() : $maxTries;

        $timeoutAt = $job->timeoutAt();
        if ($timeoutAt !== null && (int) $timeoutAt <= Carbon::now()->getTimestamp()) {
            $this->failJob($connection, $job, $e);
            return;
        }

        if ($maxTries > 0 && $job->attempts() >= $maxTries) {
            $this->failJob($connection, $job, $e);
            return;
        }
    }

    protected function failJob(string $connection, Job $job, Throwable $e): void
    {
        if ($this->failedMarkedForCurrentJob || $job->hasFailed()) {
            return;
        }

        $job->markAsFailed();
        $this->failedMarkedForCurrentJob = true;

        if ($job->isDeleted()) {
            $this->event->trigger(new JobFailed($connection, $job, $e));
            return;
        }

        try {
            $job->delete();
        } catch (Throwable $deleteError) {
            // 删除失败，但仍需要触发事件。
        }

        try {
            // job 自定义失败回调可以 throw，但这不应该影响整体失败标记与事件触发。
            $job->failed($e);
        } catch (Throwable $ignored) {
            // 忽略 job 自身的 failed() 错误 — 原始异常 $e 才是需要报告的。
        }

        $this->event->trigger(new JobFailed($connection, $job, $e));
    }

    public function sleep(int $seconds): void
    {
        if ($seconds < 1) {
            usleep($seconds * 1000000);
        } else {
            sleep($seconds);
        }
    }
}