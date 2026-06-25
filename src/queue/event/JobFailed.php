<?php

namespace think\queue\event;

use think\queue\Job;
use Throwable;

/**
 * 任务执行失败时触发。
 */
class JobFailed
{
    public function __construct(
        public readonly string $connection,
        public readonly Job $job,
        public readonly Throwable $exception,
    ) {
    }
}