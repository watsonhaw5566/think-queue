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

namespace think\queue;

use Throwable;
use think\App;
use think\helper\Arr;
use think\helper\Str;

abstract class Job
{
    private ?object $instance = null;

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    protected App $app;

    protected string $queue = '';

    protected string $connection = '';

    protected bool $deleted = false;

    protected bool $released = false;

    protected bool $failed = false;

    /**
     * 获取解码后的 payload，或 payload 中的某个字段。
     *
     * @return array<string, mixed>|mixed
     */
    public function payload(?string $name = null, mixed $default = null): mixed
    {
        if ($this->payload === null) {
            $decoded = json_decode($this->getRawBody(), true);
            $this->payload = is_array($decoded) ? $decoded : [];
        }

        if ($name === null || $name === '') {
            return $this->payload;
        }

        return Arr::get($this->payload, $name, $default);
    }

    public function fire(): void
    {
        $instance = $this->getResolvedJob();

        [, $method] = $this->getParsedJob();

        if (!is_object($instance) || !method_exists($instance, (string) $method)) {
            throw new \RuntimeException(
                sprintf('Job handler method "%s" does not exist on "%s".', $method, get_class($instance))
            );
        }

        $instance->{$method}($this, $this->payload('data'));
    }

    public function failed(Throwable $e): void
    {
        try {
            $instance = $this->getResolvedJob();
        } catch (Throwable $resolveException) {
            // 无法解析 handler，跳过自定义失败回调。
            return;
        }

        if (method_exists($instance, 'failed')) {
            $instance->failed($this->payload('data'), $e);
        }
    }

    public function delete(): void
    {
        $this->deleted = true;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function release(int $delay = 0): void
    {
        $this->released = true;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function isDeletedOrReleased(): bool
    {
        return $this->isDeleted() || $this->isReleased();
    }

    abstract public function getJobId(): mixed;

    abstract public function attempts(): int;

    abstract public function getRawBody(): string;

    /**
     * @return array{0: string, 1: string}
     */
    protected function getParsedJob(): array
    {
        $job = (string) $this->payload('job');
        $segments = explode('@', $job);

        if (count($segments) > 1) {
            return [(string) $segments[0], (string) $segments[1]];
        }

        return [(string) $segments[0], 'fire'];
    }

    /**
     * Resolve the given job handler from the container.
     */
    protected function resolve(string $name, mixed $param): object
    {
        $namespace = rtrim($this->app->getNamespace(), '\\') . '\\job\\';

        $class = str_contains($name, '\\') ? $name : $namespace . Str::studly($name);

        $resolved = $this->app->make($class, [$param], true);

        if (!is_object($resolved)) {
            throw new \RuntimeException(sprintf('Unable to resolve job handler "%s".', $class));
        }

        return $resolved;
    }

    public function getResolvedJob(): object
    {
        if ($this->instance === null) {
            [$class] = $this->getParsedJob();

            $this->instance = $this->resolve($class, $this->payload('data'));
        }

        return $this->instance;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function markAsFailed(): void
    {
        $this->failed = true;
    }

    public function maxTries(): ?int
    {
        $value = $this->payload('maxTries');
        return $value === null ? null : (int) $value;
    }

    public function timeout(): ?int
    {
        $value = $this->payload('timeout');
        return $value === null ? null : (int) $value;
    }

    public function timeoutAt(): ?int
    {
        $value = $this->payload('timeoutAt');
        return $value === null ? null : (int) $value;
    }

    public function getName(): string
    {
        return (string) $this->payload('job');
    }

    public function getConnection(): string
    {
        return $this->connection;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }
}