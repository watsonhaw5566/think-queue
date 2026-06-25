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

use Closure;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use think\App;

class Listener
{
    protected string $commandPath;

    protected string $workerCommand = '';

    protected ?Closure $outputHandler = null;

    /**
     * 指示 Listener 是否应当退出主循环。
     */
    private bool $shouldQuit = false;

    public function __construct(string $commandPath)
    {
        $this->commandPath = $commandPath;
    }

    public static function __make(App $app): self
    {
        return new self((string) $app->getRootPath());
    }

    protected function phpBinary(): string
    {
        $binary = (new PhpExecutableFinder())->find(false);

        return is_string($binary) && $binary !== '' ? $binary : PHP_BINARY;
    }

    public function listen(
        string $connection,
        string $queue,
        int $delay = 0,
        int $sleep = 3,
        int $maxTries = 0,
        int $memory = 128,
        int $timeout = 60,
    ): void {
        $this->registerSignals();

        $process = $this->makeProcess($connection, $queue, $delay, $sleep, $maxTries, $memory, $timeout);

        while (!$this->shouldQuit) {
            $this->runProcess($process, $memory);

            // 避免 CPU 空转：每次 worker 退出后短暂睡眠。
            if (!$this->shouldQuit) {
                usleep(100000);
            }
        }
    }

    public function makeProcess(
        string $connection,
        string $queue,
        int $delay,
        int $sleep,
        int $maxTries,
        int $memory,
        int $timeout,
    ): Process {
        $command = array_values(array_filter([
            $this->phpBinary(),
            'think',
            'queue:work',
            $connection,
            '--once',
            "--queue={$queue}",
            "--delay={$delay}",
            "--memory={$memory}",
            "--sleep={$sleep}",
            "--tries={$maxTries}",
        ], static fn ($value): bool => $value !== null));

        return new Process($command, $this->commandPath, null, null, $timeout);
    }

    public function runProcess(Process $process, int $memory): void
    {
        $process->run(function ($type, string $line): void {
            $this->handleWorkerOutput($type, $line);
        });

        if ($this->memoryExceeded($memory) || $this->shouldQuit) {
            $this->stop();
        }
    }

    /**
     * @param string $type Process::ERR 或 Process::OUT（由 Symfony Process 提供）
     */
    protected function handleWorkerOutput(string $type, string $line): void
    {
        if ($this->outputHandler !== null) {
            ($this->outputHandler)($type, $line);
        }
    }

    public function memoryExceeded(int $memoryLimit): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    public function stop(): void
    {
        exit(0);
    }

    public function setOutputHandler(Closure $outputHandler): void
    {
        $this->outputHandler = $outputHandler;
    }

    private function registerSignals(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldQuit = true;
            });
        }
    }
}