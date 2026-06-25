<?php

namespace think\queue\failed;

use Carbon\Carbon;
use think\Db;
use think\queue\FailedJob;

/**
 * 基于数据库表的失败任务存储。
 *
 * 配置示例（config/queue.php）：
 * ```php
 * 'failed' => [
 *     'type'  => 'database',
 *     'table' => 'failed_jobs',
 * ],
 * ```
 */
class Database extends FailedJob
{
    protected Db $db;

    protected string $table;

    /**
     * @param Db     $db    数据库连接管理实例
     * @param string $table 失败任务表名
     */
    public function __construct(Db $db, string $table)
    {
        $this->db    = $db;
        $this->table = $table;
    }

    /**
     * 由容器调用的工厂方法，从配置中读取表名并创建实例。
     *
     * @param Db                        $db
     * @param array<string, mixed>      $config
     */
    public static function __make(Db $db, array $config): self
    {
        return new self($db, (string) $config['table']);
    }

    public function log(string $connection, string $queue, string $payload, \Throwable $exception): mixed
    {
        $fail_time = Carbon::now()->toDateTimeString();

        $exception = (string) $exception;

        return $this->getTable()->insertGetId(compact(
            'connection',
            'queue',
            'payload',
            'exception',
            'fail_time'
        ));
    }

    /**
     * @return array<int, object|array<string, mixed>>
     */
    public function all(): array
    {
        return collect($this->getTable()->order('id', 'desc')->select())->all();
    }

    /**
     * @param mixed $id
     * @return object|array<string, mixed>|null
     */
    public function find(mixed $id): mixed
    {
        return $this->getTable()->find($id);
    }

    /**
     * @param mixed $id
     */
    public function forget(mixed $id): bool
    {
        return $this->getTable()->where('id', $id)->delete() > 0;
    }

    public function flush(): void
    {
        $this->getTable()->delete(true);
    }

    protected function getTable()
    {
        return $this->db->name($this->table);
    }
}