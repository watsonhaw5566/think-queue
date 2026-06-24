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

use Closure;
use Exception;
use RedisException;
use RuntimeException;
use think\helper\Str;
use think\queue\Connector;
use think\queue\InteractsWithTime;
use think\queue\job\Redis as RedisJob;

class Redis extends Connector
{
    use InteractsWithTime;

    /** @var \Redis */
    protected $redis;

    protected string $default;

    protected ?int $retryAfter = 60;

    protected ?int $blockFor = null;

    /**
     * Script SHA1 cache (lower-cased method name => sha1).
     *
     * @var array<string, string>
     */
    private array $scriptCache = [];

    /**
     * Detect "connection went away" or other recoverable Redis errors.
     */
    private static function isRecoverableRedisError(RedisException $e): bool
    {
        $message = strtolower($e->getMessage());

        return Str::contains($message, [
            'went away',
            'connection lost',
            'connection refused',
            'connection reset',
            'broken pipe',
            'timeout',
            'econnreset',
        ]);
    }

    public function __construct($redis, string $default = 'default', int $retryAfter = 60, ?int $blockFor = null)
    {
        $this->redis      = $redis;
        $this->default    = $default;
        $this->retryAfter = $retryAfter;
        $this->blockFor   = $blockFor;
    }

    /**
     * @param array<string, mixed> $config
     * @throws Exception When the Redis extension is not available.
     */
    public static function __make(array $config): self
    {
        if (!extension_loaded('redis')) {
            throw new Exception('redis 扩展未安装');
        }

        $redis = new class($config) {
            /** @var array<string, mixed> */
            protected array $config;

            protected ?\Redis $client = null;

            /**
             * @param array<string, mixed> $config
             */
            public function __construct(array $config)
            {
                $this->config = $config;
                $this->client = $this->createClient();
            }

            protected function createClient(): \Redis
            {
                $config = $this->config;
                $host   = (string) ($config['host'] ?? '127.0.0.1');
                $port   = (int) ($config['port'] ?? 6379);
                $timeout = (float) ($config['timeout'] ?? 5);
                $persistent = (bool) ($config['persistent'] ?? false);
                $password = $config['password'] ?? '';
                $select   = (int) ($config['select'] ?? 0);

                $client = new \Redis;
                $connected = $persistent
                    ? @$client->pconnect($host, $port, $timeout)
                    : @$client->connect($host, $port, $timeout);

                if (!$connected) {
                    throw new RuntimeException(
                        sprintf('Unable to connect to Redis server at %s:%d', $host, $port)
                    );
                }

                if ('' !== $password) {
                    $client->auth($password);
                }

                if (0 !== $select) {
                    $client->select($select);
                }

                return $client;
            }

            /**
             * Magic method proxy — transparently reconnects on transient failures
             * and retries the original command exactly once.
             *
             * @param array<int, mixed> $arguments
             * @return mixed
             */
            public function __call(string $name, array $arguments): mixed
            {
                try {
                    return $this->client !== null
                        ? call_user_func_array([$this->client, $name], $arguments)
                        : call_user_func_array([$this->createClient(), $name], $arguments);
                } catch (RedisException $e) {
                    if (!Redis::isRecoverableRedisError($e)) {
                        throw $e;
                    }

                    // Reconnect and retry the same command exactly once.
                    $this->client = $this->createClient();

                    return call_user_func_array([$this->client, $name], $arguments);
                }
            }
        };

        return new self(
            $redis,
            (string) ($config['queue'] ?? 'default'),
            (int) ($config['retry_after'] ?? 60),
            isset($config['block_for']) ? (int) $config['block_for'] : null
        );
    }

    public function size(?string $queue = null): int
    {
        $queue = $this->getQueue($queue);

        return $this->withRedisRetry(function () use ($queue): int {
            return (int) $this->redis->lLen($queue)
                + (int) $this->redis->zCard("{$queue}:delayed")
                + (int) $this->redis->zCard("{$queue}:reserved");
        });
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue);
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->withRedisRetry(function () use ($queue, $payload): ?string {
            if ($this->redis->rPush($this->getQueue($queue), $payload)) {
                $decoded = json_decode($payload, true);
                return is_array($decoded) ? ($decoded['id'] ?? null) : null;
            }
            return null;
        });
    }

    public function later(\DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->laterRaw($delay, $this->createPayload($job, $data), $queue);
    }

    /**
     * @param \DateTimeInterface|int $delay
     */
    protected function laterRaw(\DateTimeInterface|int $delay, string $payload, ?string $queue = null): mixed
    {
        return $this->withRedisRetry(function () use ($queue, $delay, $payload): ?string {
            if ($this->redis->zadd(
                $this->getQueue($queue) . ':delayed',
                $this->availableAt($delay),
                $payload
            )) {
                $decoded = json_decode($payload, true);
                return is_array($decoded) ? ($decoded['id'] ?? null) : null;
            }
            return null;
        });
    }

    public function pop(?string $queue = null): ?RedisJob
    {
        $prefixed = $this->getQueue($queue);

        $this->migrate($prefixed);

        $nextJob = $this->retrieveNextJob($prefixed);

        if (empty($nextJob[0]) || empty($nextJob[1])) {
            return null;
        }

        [$job, $reserved] = $nextJob;

        return new RedisJob($this->app, $this, $job, $reserved, $this->connection, $queue);
    }

    /**
     * Migrate any delayed or expired jobs onto the primary queue.
     */
    protected function migrate(string $queue): void
    {
        $this->migrateExpiredJobs($queue . ':delayed', $queue);

        if (null !== $this->retryAfter) {
            $this->migrateExpiredJobs($queue . ':reserved', $queue);
        }
    }

    /**
     * Move expired jobs from a delayed/reserved sorted set to the main list.
     *
     * Uses WATCH + MULTI/EXEC with a retry loop to safely handle concurrent
     * modifications by other workers.
     */
    public function migrateExpiredJobs(string $from, string $to, bool $attempt = true): void
    {
        $this->withRedisRetry(function () use ($from, $to): void {
            $maxAttempts = 3;

            for ($attemptCount = 0; $attemptCount < $maxAttempts; $attemptCount++) {
                $this->redis->watch($from);

                $jobs = $this->redis->zRangeByScore($from, '-inf', (string) $this->currentTime());

                if (empty($jobs)) {
                    $this->redis->unwatch();
                    return;
                }

                $jobCount = count($jobs);

                $this->redis->multi();
                try {
                    $this->redis->zRemRangeByRank($from, 0, $jobCount - 1);

                    // Push in chunks to avoid stack overflow / buffer limits.
                    for ($i = 0; $i < $jobCount; $i += 100) {
                        $chunk = array_slice($jobs, $i, 100);
                        $this->redis->rPush($to, ...$chunk);
                    }

                    $result = $this->redis->exec();

                    if (false === $result) {
                        // Another client modified `$from`; back off and retry.
                        usleep(100000 * ($attemptCount + 1));
                        continue;
                    }

                    return;
                } catch (RedisException $e) {
                    try {
                        $this->redis->discard();
                    } catch (RedisException $discardException) {
                        // discard() may throw if we're not in MULTI — ignore.
                    }
                    throw $e;
                }
            }
        });
    }

    /**
     * Retrieve the next job from the queue using an atomic Lua script.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function retrieveNextJob(string $queue): array
    {
        if (null !== $this->blockFor) {
            return $this->blockingPop($queue);
        }

        $script = <<<'LUA'
            local queue = KEYS[1]
            local reserved = KEYS[2]
            local availableAt = ARGV[1]

            local job = redis.call('LPOP', queue)
            if job == false then
                return {false, false}
            end

            local decoded = cjson.decode(job)
            decoded.attempts = (decoded.attempts or 0) + 1
            local reservedPayload = cjson.encode(decoded)

            redis.call('ZADD', reserved, availableAt, reservedPayload)
            return {job, reservedPayload}
        LUA;

        return $this->withRedisRetry(function () use ($script, $queue): array {
            $result = $this->evalLua($script, 'retrieveNextJob', [
                $queue,
                $queue . ':reserved',
            ], [
                (string) $this->availableAt($this->retryAfter ?? 60),
            ]);

            if (!is_array($result) || count($result) < 2) {
                return [null, null];
            }

            $job = $result[0] === false ? null : (is_string($result[0]) ? $result[0] : null);
            $reserved = $result[1] === false ? null : (is_string($result[1]) ? $result[1] : null);

            return [$job, $reserved];
        });
    }

    /**
     * Retrieve the next job by blocking-pop. Uses a Lua script to keep
     * the "pop → increment attempts → zadd reserved" operation atomic.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function blockingPop(string $queue): array
    {
        // BLPOP cannot be called inside a script/MULTI, so we use it as the
        // blocking step and then atomically push to reserved with a script.
        $rawBody = $this->withRedisRetry(function () use ($queue): mixed {
            return $this->redis->blpop($queue, (int) $this->blockFor);
        });

        if (empty($rawBody) || !is_array($rawBody) || !isset($rawBody[1]) || !is_string($rawBody[1])) {
            return [null, null];
        }

        $script = <<<'LUA'
            local reserved = KEYS[1]
            local availableAt = ARGV[1]
            local payload = ARGV[2]

            local decoded = cjson.decode(payload)
            decoded.attempts = (decoded.attempts or 0) + 1
            local reservedPayload = cjson.encode(decoded)

            redis.call('ZADD', reserved, availableAt, reservedPayload)
            return reservedPayload
        LUA;

        $reserved = $this->withRedisRetry(function () use ($script, $queue, $rawBody): mixed {
            return $this->evalLua($script, 'blockingPop', [
                $queue . ':reserved',
            ], [
                (string) $this->availableAt($this->retryAfter ?? 60),
                $rawBody[1],
            ]);
        });

        if (!is_string($reserved)) {
            return [null, null];
        }

        return [$rawBody[1], $reserved];
    }

    public function deleteReserved(string $queue, RedisJob $job): void
    {
        $this->withRedisRetry(function () use ($queue, $job): void {
            $this->redis->zRem(
                $this->getQueue($queue) . ':reserved',
                $job->getReservedJob()
            );
        });
    }

    public function deleteAndRelease(string $queue, RedisJob $job, int $delay): void
    {
        $prefixed = $this->getQueue($queue);
        $reserved = $job->getReservedJob();

        $this->withRedisRetry(function () use ($prefixed, $reserved, $delay): void {
            $this->redis->multi();
            try {
                $this->redis->zRem($prefixed . ':reserved', $reserved);
                $this->redis->zAdd($prefixed . ':delayed', $this->availableAt($delay), $reserved);

                if (false === $this->redis->exec()) {
                    $this->redis->discard();
                }
            } catch (RedisException $e) {
                try {
                    $this->redis->discard();
                } catch (RedisException $discardException) {
                    // ignore
                }
                throw $e;
            }
        });
    }

    /**
     * Run a Lua script on the Redis client, falling back to SCRIPT LOAD
     * + EVALSHA if the script is not yet in the server cache.
     *
     * @param array<int, string> $keys
     * @param array<int, string> $argv
     * @return mixed Whatever the Lua script returns.
     */
    protected function evalLua(string $script, string $cacheKey, array $keys, array $argv): mixed
    {
        $numKeys = count($keys);
        $args = array_merge($keys, $argv);

        // Try EVALSHA first using a cached SHA1.
        if (isset($this->scriptCache[$cacheKey])) {
            try {
                $result = $this->redis->evalSha(
                    $this->scriptCache[$cacheKey],
                    $args,
                    $numKeys
                );
                if ($this->redis->getLastError() === null || !str_contains((string) $this->redis->getLastError(), 'NOSCRIPT')) {
                    return $result;
                }
            } catch (RedisException $e) {
                if (!str_contains(strtolower($e->getMessage()), 'noscript')) {
                    throw $e;
                }
            }
        }

        // Fall back to EVAL, which also loads the script into the server cache.
        $sha = sha1($script);
        $this->scriptCache[$cacheKey] = $sha;

        return $this->redis->eval($script, $args, $numKeys);
    }

    /**
     * Wrap a Redis operation with automatic retry on transient failures.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    protected function withRedisRetry(callable $operation): mixed
    {
        return $this->retry(
            $operation,
            static fn (\Throwable $e): bool => $e instanceof RedisException && self::isRecoverableRedisError($e),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function createPayloadArray(object|string $job, mixed $data = ''): array
    {
        return array_merge(parent::createPayloadArray($job, $data), [
            'id'       => $this->getRandomId(),
            'attempts' => 0,
        ]);
    }

    protected function getRandomId(): string
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                // Fall through to Str::random.
            }
        }

        return Str::random(32);
    }

    protected function getQueue(?string $queue): string
    {
        $name = $queue ?? $this->default;
        return "{queues:{$name}}";
    }
}