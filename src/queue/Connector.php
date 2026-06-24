<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\queue;

use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use think\App;
use think\Config;
use think\Event;

/**
 * Queue connector base class.
 *
 * @property-read App    $app
 * @property-read Config $config
 * @property-read Event  $event
 */
abstract class Connector
{
    /**
     * Application container (includes dynamically bound services like `config` and `event`).
     *
     * @var App&object{config: Config, event: Event}
     */
    protected App $app;

    /**
     * The connector name for the queue.
     */
    protected string $connection = '';

    /** @var array<string, mixed> */
    protected array $options = [];

    /**
     * Maximum retries for operations that may fail due to transient issues.
     */
    protected int $maxAttempts = 3;

    /**
     * Delay in microseconds between retry attempts.
     */
    protected int $retryDelay = 100000;

    abstract public function size(?string $queue = null): int;

    /**
     * @param object|string $job
     * @param mixed $data
     */
    abstract public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed;

    /**
     * @param object|string $job
     * @param mixed $data
     */
    public function pushOn(string $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    abstract public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed;

    /**
     * @param DateTimeInterface|int $delay
     * @param object|string $job
     * @param mixed $data
     */
    abstract public function later(DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed;

    /**
     * @param DateTimeInterface|int $delay
     * @param object|string $job
     * @param mixed $data
     */
    public function laterOn(string $queue, DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * @param iterable<int, object|string> $jobs
     * @param mixed $data
     */
    public function bulk(iterable $jobs, mixed $data = '', ?string $queue = null): void
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    abstract public function pop(?string $queue = null): ?Job;

    /**
     * Create a payload string from the given job and data.
     *
     * @param object|string $job
     * @param mixed $data
     * @throws InvalidArgumentException When the payload cannot be JSON-encoded.
     */
    protected function createPayload(object|string $job, mixed $data = ''): string
    {
        $payload = $this->createPayloadArray($job, $data);

        $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException(
                'Unable to create payload: ' . json_last_error_msg()
            );
        }

        return $payload;
    }

    /**
     * Create a payload array from the given job and data.
     *
     * @param object|string $job
     * @param mixed $data
     * @return array<string, mixed>
     */
    protected function createPayloadArray(object|string $job, mixed $data = ''): array
    {
        return is_object($job)
            ? $this->createObjectPayload($job)
            : $this->createPlainPayload($job, $data);
    }

    /**
     * Create a payload for a plain job.
     *
     * @param mixed $data
     * @return array<string, mixed>
     */
    protected function createPlainPayload(string $job, mixed $data): array
    {
        return [
            'job'      => $job,
            'maxTries' => null,
            'timeout'  => null,
            'data'     => $data,
        ];
    }

    /**
     * Create a payload for an object-based queue handler.
     *
     * @return array<string, mixed>
     */
    protected function createObjectPayload(object $job): array
    {
        return [
            'job'       => CallQueuedHandler::class . '@call',
            'maxTries'  => $job->tries ?? null,
            'timeout'   => $job->timeout ?? null,
            'timeoutAt' => $this->getJobExpiration($job),
            'data'      => [
                'commandName' => get_class($job),
                'command'     => $this->serializeJob(clone $job),
            ],
        ];
    }

    /**
     * Safely serialize a job object, logging any serialization warnings.
     */
    protected function serializeJob(object $job): string
    {
        $serialized = @serialize($job);

        if (false === $serialized) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to serialize job "%s": job may contain non-serializable resources.',
                    get_class($job)
                )
            );
        }

        return $serialized;
    }

    /**
     * Get the expiration timestamp for a job.
     */
    public function getJobExpiration(object $job): ?int
    {
        if (!method_exists($job, 'retryUntil') && !isset($job->timeoutAt)) {
            return null;
        }

        $expiration = $job->timeoutAt ?? $job->retryUntil();

        if ($expiration instanceof DateTimeInterface) {
            return $expiration->getTimestamp();
        }

        if ($expiration instanceof DateInterval) {
            return time() + $expiration->s + ($expiration->i * 60) + ($expiration->h * 3600) + ($expiration->d * 86400);
        }

        return is_numeric($expiration) ? (int) $expiration : null;
    }

    /**
     * Set a meta value on a payload.
     *
     * @param mixed $value
     * @throws InvalidArgumentException When the payload is invalid JSON.
     */
    protected function setMeta(string $payload, string $key, mixed $value): string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid payload JSON: cannot set metadata.');
        }

        $decoded[$key] = $value;

        $reencoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException(
                'Unable to re-encode payload: ' . json_last_error_msg()
            );
        }

        return $reencoded;
    }

    /**
     * Retry an operation with exponential backoff on transient failures.
     *
     * @template T
     * @param callable(): T $operation
     * @param callable(\Throwable): bool $isTransient  Return true to retry.
     * @return T
     * @throws \Throwable Last thrown exception if all attempts fail.
     */
    protected function retry(callable $operation, callable $isTransient, int $maxAttempts = null): mixed
    {
        $maxAttempts ??= $this->maxAttempts;
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $lastException = $e;
                if (!$isTransient($e)) {
                    throw $e;
                }
                $attempts++;
                if ($attempts < $maxAttempts) {
                    usleep($this->retryDelay * $attempts);
                }
            }
        }

        throw $lastException;
    }

    public function setApp(App $app): static
    {
        $this->app = $app;
        return $this;
    }

    public function getApp(): App
    {
        return $this->app;
    }

    public function getConnection(): string
    {
        return $this->connection;
    }

    public function setConnection(string $name): static
    {
        $this->connection = $name;
        return $this;
    }
}