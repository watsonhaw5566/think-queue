<?php

namespace think\queue\event;

use think\queue\Job;

/**
 * 任务即将开始执行时触发。
 */
class JobProcessing
{
    public function __construct(
        public readonly string $connection,
        public readonly Job $job,
    ) {
    }
}