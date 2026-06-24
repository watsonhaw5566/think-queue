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

use Carbon\Carbon;
use stdClass;
use think\Db;
use think\db\ConnectionInterface;
use think\db\Query;
use think\queue\Connector;
use think\queue\InteractsWithTime;
use think\queue\job\Database as DatabaseJob;

class Database extends Connector
{
    use InteractsWithTime;

    protected ConnectionInterface $db;

    protected string $table;

    protected string $default;

    protected int $retryAfter = 60;

    public function __construct(
        ConnectionInterface $db,
        string $table,
        string $default = 'default',
        int $retryAfter = 60,
    ) {
        $this->db         = $db;
        $this->table      = $table;
        $this->default    = $default;
        $this->retryAfter = $retryAfter;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function __make(Db $db, array $config): self
    {
        $connection = $db->connect($config['connection'] ?? null);

        return new self(
            $connection,
            (string) $config['table'],
            (string) ($config['queue'] ?? 'default'),
            (int) ($config['retry_after'] ?? 60),
        );
    }

    public function size(?string $queue = null): int
    {
        return (int) $this->db
            ->name($this->table)
            ->where('queue', $this->getQueue($queue))
            ->count();
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->pushToDatabase($queue, $this->createPayload($job, $data));
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->pushToDatabase($queue, $payload);
    }

    public function later(\DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->pushToDatabase($queue, $this->createPayload($job, $data), $delay);
    }

    /**
     * @param iterable<int, object|string> $jobs
     * @param mixed $data
     */
    public function bulk(iterable $jobs, mixed $data = '', ?string $queue = null): void
    {
        $queue = $this->getQueue($queue);
        $availableAt = $this->availableAt();
        $currentTime = $this->currentTime();

        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = [
                'queue'          => $queue,
                'attempts'       => 0,
                'reserve_time'   => null,
                'available_time' => $availableAt,
                'create_time'    => $currentTime,
                'payload'        => $this->createPayload($job, $data),
            ];
        }

        if ($rows !== []) {
            $this->db->name($this->table)->insertAll($rows);
        }
    }

    /**
     * 重新发布任务
     */
    public function release(string $queue, stdClass $job, int $delay): mixed
    {
        return $this->pushToDatabase(
            $queue,
            is_string($job->payload) ? $job->payload : (string) $job->payload,
            $delay,
            (int) $job->attempts
        );
    }

    /**
     * Push a raw payload to the database with a given delay.
     */
    protected function pushToDatabase(?string $queue, string $payload, \DateTimeInterface|int $delay = 0, int $attempts = 0): mixed
    {
        return $this->db->name($this->table)->insertGetId([
            'queue'          => $this->getQueue($queue),
            'attempts'       => $attempts,
            'reserve_time'   => null,
            'available_time' => $this->availableAt($delay),
            'create_time'    => $this->currentTime(),
            'payload'        => $payload,
        ]);
    }

    public function pop(?string $queue = null): ?DatabaseJob
    {
        $queue = $this->getQueue($queue);

        $result = $this->db->transaction(function () use ($queue): ?DatabaseJob {
            $job = $this->getNextAvailableJob($queue);
            if ($job === null) {
                return null;
            }

            $job = $this->markJobAsReserved($job);

            return new DatabaseJob($this->app, $this, $job, $this->connection, $queue);
        });

        return $result instanceof DatabaseJob ? $result : null;
    }

    /**
     * 获取下个有效任务。通过 SELECT … FOR UPDATE 锁定行，避免并发 worker 拿到同一任务。
     */
    protected function getNextAvailableJob(string $queue): ?stdClass
    {
        $expiration = Carbon::now()->subSeconds($this->retryAfter)->getTimestamp();

        $job = $this->db
            ->name($this->table)
            ->lock(true)
            ->where('queue', $this->getQueue($queue))
            ->where(function (Query $query) use ($expiration): void {
                $query->where(function (Query $query): void {
                    $query->whereNull('reserve_time')
                        ->where('available_time', '<=', $this->currentTime());
                });

                $query->whereOr(function (Query $query) use ($expiration): void {
                    $query->whereNotNull('reserve_time')
                        ->where('reserve_time', '<=', $expiration);
                });
            })
            ->order('id', 'asc')
            ->find();

        if ($job === null || $job === false) {
            return null;
        }

        return (object) $job;
    }

    /**
     * 标记任务正在执行。
     */
    protected function markJobAsReserved(stdClass $job): stdClass
    {
        $job->reserve_time = $this->currentTime();
        $job->attempts     = ((int) $job->attempts) + 1;

        $updated = $this->db
            ->name($this->table)
            ->where('id', $job->id)
            ->update([
                'reserve_time' => $job->reserve_time,
                'attempts'     => $job->attempts,
            ]);

        // 受影响行数为 0 意味着在读取和更新之间有变化 — 让上层处理这种竞态。
        if ($updated === 0) {
            return $job;
        }

        return $job;
    }

    /**
     * 删除任务。在事务中执行，并使用乐观锁防止重复删除。
     */
    public function deleteReserved(mixed $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $row = $this->db->name($this->table)->lock(true)->find($id);

            if ($row === null || $row === false || $row === []) {
                return;
            }

            $this->db->name($this->table)->where('id', $id)->delete();
        });
    }

    protected function getQueue(?string $queue): string
    {
        return $queue ?? $this->default;
    }
}