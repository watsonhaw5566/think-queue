<?php

namespace think\queue\failed;

use think\queue\FailedJob;

/**
 * 「不存储」失败任务的空实现。
 *
 * 当 `config/queue.php` 中 `failed.type` 设为 `'none'` 时使用此实现。
 * 所有写操作都会被忽略，读操作返回空结果。
 */
class None extends FailedJob
{
    public function log(string $connection, string $queue, string $payload, \Throwable $exception): mixed
    {
        return null;
    }

    /**
     * @return array<int, object|array<string, mixed>>
     */
    public function all(): array
    {
        return [];
    }

    /**
     * @param mixed $id
     * @return object|array<string, mixed>|null
     */
    public function find(mixed $id): mixed
    {
        return null;
    }

    /**
     * @param mixed $id
     */
    public function forget(mixed $id): bool
    {
        return true;
    }

    public function flush(): void
    {
    }
}