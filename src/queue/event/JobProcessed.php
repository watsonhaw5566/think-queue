<?php

namespace think\queue\event;

use think\queue\Job;

/**
 * 任务执行完毕（成功）时触发。
 */
class JobProcessed
{
    public function __construct(
        public readonly string $connection,
        public readonly Job $job,
    ) {
    }
}