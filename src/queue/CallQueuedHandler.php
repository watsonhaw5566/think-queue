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

use Throwable;
use think\App;
use think\Config;
use think\Event;

/**
 * Handles queued commands.
 *
 * @property-read App    $app
 * @property-read Config $config
 * @property-read Event  $event
 */
class CallQueuedHandler
{
    /**
     * Application container (includes dynamically bound services like `config` and `event`).
     *
     * @var App&object{config: Config, event: Event}
     */
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function call(Job $job, array $data): void
    {
        $command = $this->safeUnserialize($data['command'] ?? '');

        if (!is_object($command)) {
            $job->markAsFailed();
            $job->delete();
            return;
        }

        $this->app->invoke([$command, 'handle'], [$job]);

        if (!$job->isDeletedOrReleased()) {
            $job->delete();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function failed(array $data, ?Throwable $exception = null): void
    {
        $command = $this->safeUnserialize($data['command'] ?? '');

        if (!is_object($command) || !method_exists($command, 'failed')) {
            return;
        }

        // 同时兼容一个参数和两个参数的 failed() 方法。
        $reflection = new \ReflectionMethod($command, 'failed');

        if ($reflection->getNumberOfParameters() >= 1) {
            $command->failed($exception ?? new \RuntimeException('Job execution failed.'));
        } else {
            $command->failed();
        }
    }

    /**
     * 安全地反序列化 payload 中的命令对象。
     */
    private function safeUnserialize(mixed $serialized): ?object
    {
        if (!is_string($serialized) || $serialized === '') {
            return null;
        }

        // PHP 7.0+ 支持 allowed_classes，但这里我们依赖用户代码
        // 的完整性。若反序列化本身失败，应当安静地返回 null。
        $result = @unserialize($serialized);

        return is_object($result) ? $result : null;
    }
}