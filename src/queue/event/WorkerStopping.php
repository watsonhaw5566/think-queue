<?php

namespace think\queue\event;

/**
 * Worker 即将停止时触发。
 */
class WorkerStopping
{
    public function __construct(
        public readonly int $status = 0,
    ) {
    }
}