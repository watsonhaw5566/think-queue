# think-queue for ThinkPHP 8

一个为 ThinkPHP 8 提供异步任务队列能力的组件，支持 **PHP 8.0+**。

内置驱动：

- `sync` —— 同步执行（默认，调试用）
- `database` —— 数据库驱动
- `redis` —— Redis 驱动
- 自定义驱动（传入完整类名即可）

## 安装

```bash
composer require topthink/think-queue
```

安装完成后会自动在 `config/queue.php` 生成配置文件。

## 配置

配置文件位于 `config/queue.php`，主要结构如下：

```php
return [
    // 默认连接
    'default' => 'sync',

    // 各连接配置
    'connections' => [
        'sync' => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            'queue'      => 'default',
            'table'      => 'jobs',
            'connection' => null,
        ],
        'redis' => [
            'type'       => 'redis',
            'queue'      => 'default',
            'host'       => '127.0.0.1',
            'port'       => 6379,
            'password'   => '',
            'select'     => 0,
            'timeout'    => 0,
            'persistent' => false,
        ],
    ],

    // 失败任务表配置
    'failed' => [
        'type'  => 'none',
        'table' => 'failed_jobs',
    ],
];
```

## 创建任务类

推荐使用 `app\job` 作为任务类的命名空间，也可放在任意可自动加载的目录。

任务类无需继承任何类，只需约定：

| 方法 | 说明 |
|------|------|
| `fire(Job $job, mixed $data)` / 任意自定义方法名 | 任务执行入口，接受当前任务对象和自定义数据 |
| `failed(mixed $data)` | （可选）任务达到最大重试次数后调用 |

### 单任务类示例

```php
namespace app\job;

use think\queue\Job;

class SendEmail
{
    public function fire(Job $job, mixed $data): void
    {
        // 执行具体任务，例如发送邮件
        // ...

        // 检查当前重试次数
        if ($job->attempts() > 3) {
            // 已重试 3 次仍未成功
            $job->delete();
            return;
        }

        // 执行成功后手动删除任务（否则会重复执行）
        $job->delete();

        // 或重新发布（延迟执行）
        // $job->release(60);
    }

    public function failed(mixed $data): void
    {
        // 任务达到最大重试次数后的逻辑
    }
}
```

### 多任务类示例（一个类多个任务入口）

```php
namespace app\job;

use think\queue\Job;

class Notification
{
    public function sendEmail(Job $job, mixed $data): void
    {
        // ...
    }

    public function sendSms(Job $job, mixed $data): void
    {
        // ...
    }

    public function failed(mixed $data): void
    {
        // ...
    }
}
```

## 使用 `Queueable` 链式调用

任务类可以使用 `think\queue\Queueable` trait，在发布任务时进行链式配置：

```php
use think\queue\ShouldQueue;
use think\queue\Queueable;

class SendEmail implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // 任务逻辑
    }
}
```

发布时：

```php
use think\facade\Queue;

(new SendEmail())
    ->onConnection('redis')   // 指定连接
    ->onQueue('high')         // 指定队列
    ->delay(30)               // 延迟 30 秒
    ->dispatch();             // 发布到队列

// 或直接使用门面：
Queue::push(SendEmail::class);
Queue::later(60, SendEmail::class, ['key' => 'value']);
```

## 发布任务

通过门面类 `\think\facade\Queue` 发布任务：

```php
use think\facade\Queue;

// 立即发布（使用默认连接与队列）
Queue::push('app\job\SendEmail', ['to' => 'user@example.com']);

// 指定队列
Queue::push('app\job\SendEmail', ['to' => 'user@example.com'], 'high');

// 延迟发布（$delay 秒后执行）
Queue::later(300, 'app\job\SendEmail', ['to' => 'user@example.com']);

// 多任务类时，使用 @method 语法
Queue::push('app\job\Notification@sendSms', ['to' => '13800138000']);
```

`push` 与 `later` 的返回值：

- `sync` 驱动：`null`（同步执行完成）
- `database` 驱动：任务 ID（`int`）
- `redis` 驱动：`true`（成功时）

## 监听任务并执行

### `queue:work` —— 单进程消费（推荐常驻）

```bash
# 使用默认连接
php think queue:work

# 指定连接和队列
php think queue:work redis --queue=high

# 仅执行一次后退出
php think queue:work --once

# 配置参数
php think queue:work redis --queue=default --delay=0 --memory=128 --sleep=3 --tries=0
```

参数说明：

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `connection` | `config('queue.default')` | 使用的队列连接名称 |
| `--queue` | `default` | 监听的队列名，多个用逗号分隔 |
| `--once` | — | 仅处理一个任务后退出 |
| `--delay` | `0` | 任务失败后重新入队的延迟秒数 |
| `--memory` | `128` | 内存限制（MB），超出后进程终止 |
| `--sleep` | `3` | 队列空时的休眠秒数 |
| `--tries` | `0` | 任务最大重试次数，`0` 表示无限重试 |

### `queue:listen` —— 守护进程监听（会反复 fork worker）

```bash
php think queue:listen
php think queue:listen redis --queue=high --delay=0 --memory=128 --sleep=3 --tries=0
```

> 生产环境推荐配合 **supervisor** 或 systemd 保证进程常驻。

## 数据库驱动表迁移

使用 `database` 驱动前需要创建 `jobs` 数据表：

```bash
php think queue:table
php think migrate:run
```

如果需要记录失败任务：

```bash
php think queue:failed-table
php think migrate:run
```

## 失败任务管理

| 命令 | 作用 |
|------|------|
| `php think queue:failed` | 列出所有失败的任务 |
| `php think queue:retry <id>` | 重新发布指定 ID 的失败任务 |
| `php think queue:retry all` | 重新发布所有失败任务 |
| `php think queue:forget <id>` | 删除指定 ID 的失败任务 |
| `php think queue:flush` | 清空所有失败任务 |

## 任务事件

组件在任务生命周期会触发以下事件，可在事件订阅者中监听：

| 事件类 | 触发时机 | 公开属性（均为 `readonly`） |
|--------|----------|-----------------------------|
| `think\queue\event\JobProcessing` | 任务开始执行前 | `$connection`, `$job` |
| `think\queue\event\JobProcessed` | 任务执行完成后 | `$connection`, `$job` |
| `think\queue\event\JobExceptionOccurred` | 任务执行过程中出现异常 | `$connection`, `$job`, `$exception` |
| `think\queue\event\JobFailed` | 任务失败（超过最大重试次数） | `$connection`, `$job`, `$exception` |
| `think\queue\event\WorkerStopping` | Worker 进程停止前 | `$status` |

示例：

```php
use think\queue\event\JobFailed;

$this->app->event->listen(JobFailed::class, function (JobFailed $event): void {
    // 通过 public readonly 属性直接访问
    $connection = $event->connection;
    $exception  = $event->exception;
    $payload    = $event->job->payload();

    // 日志、告警等
});
```

## IDE 支持

本项目在根目录提供 `.phpstorm.meta.php`，为 PhpStorm / JetBrains IDE 提供以下增强：

- `\think\facade\Queue::push() / later() / connection() / ...` 静态方法完整签名提示
- `Queue::connection('redis')` → 动态推断出对应的连接器类（`Redis` / `Database` / `Sync`）
- `$app->make('queue')` / `$app->make('queue.failer')` / `ContainerInterface::get(...)` 返回类型推断
- 连接名 / 队列名参数的候选值补全（从 `queue.connections.*.type` 与 `queue.connections.*.queue` 读取）
- `queue:work`、`queue:listen` 命令行类构造时的依赖注入提示

此外，核心代码在 PHP 8.0+ 层面也做了全面的类型声明：

- `Queueable` trait：`onConnection() / onQueue() / delay()` 均返回 `: static`，支持链式调用与 IDE 跟踪
- `InteractsWithTime`：所有时间处理方法均声明参数类型（`DateTimeInterface|int`）与 `: int` 返回类型
- `ShouldQueue` 接口：附带完整使用示例的文档注释
- `FailedJob` 抽象类：所有方法均声明参数与返回类型
- 事件类：全面使用 PHP 8.1 构造函数属性提升（`public readonly`），直接通过 `$event->connection` 访问，无需 getter 方法
- 所有 `queue:xxx` 命令类：方法均声明 `: void` 返回类型，参数读取均做显式类型转换（`(int)` / `(string)`）

## 版本与要求

| 项 | 版本 |
|----|------|
| ThinkPHP | `^8.0` |
| PHP | `>= 8.0` |
| 可选扩展 | `redis`（使用 redis 驱动时）、`pcntl`（信号处理，可选） |