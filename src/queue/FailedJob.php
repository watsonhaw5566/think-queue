<?php

namespace think\queue;

/**
 * 失败任务存储的抽象基类。
 *
 * 子类负责把失败任务写入具体存储（数据库、文件等），供后续的 `queue:retry` /
 * `queue:forget` 等命令查询与操作。
 */
abstract class FailedJob
{
    /**
     * 记录一条失败任务。
     *
     * @param string    $connection  连接名
     * @param string    $queue       队列名
     * @param string    $payload     任务的 JSON 序列化内容
     * @param \Throwable $exception   导致失败的异常（会被转成字符串存入）
     * @return int|string|null       新记录的 ID（若存储支持）
     */
    abstract public function log(string $connection, string $queue, string $payload, \Throwable $exception): mixed;

    /**
     * 取出全部失败任务。
     *
     * @return array<int, object|array<string, mixed>>
     */
    abstract public function all(): array;

    /**
     * 按 ID 查找单条失败任务。
     *
     * @param mixed $id
     * @return object|array<string, mixed>|null
     */
    abstract public function find(mixed $id): mixed;

    /**
     * 删除指定 ID 的失败任务。
     *
     * @param mixed $id
     */
    abstract public function forget(mixed $id): bool;

    /**
     * 清空全部失败任务。
     */
    abstract public function flush(): void;
}