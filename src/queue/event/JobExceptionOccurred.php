<?php

namespace think\queue\event;

use think\queue\Job;
use Throwable;

/**
 * 任务抛出异常（但尚未判定失败）时触发。
 */
class JobExceptionOccurred
{
    public function __construct(
        public readonly string $connectionName,
        public readonly Job $job,
        public readonly Throwable $exception,
    ) {
    }
}