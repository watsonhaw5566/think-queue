<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\queue;

/**
 * 标记接口：表示一个类可以被异步推送到队列。
 *
 * 典型用法是配合 {@see Queueable} trait，并定义 `handle()` 方法：
 *
 * ```php
 * use think\queue\ShouldQueue;
 * use think\queue\Queueable;
 * use think\queue\Job;
 *
 * class SendEmail implements ShouldQueue
 * {
 *     use Queueable;
 *
 *     // 可选：最大重试次数（null 表示使用 Worker 默认值）
 *     public ?int $tries = null;
 *
 *     // 可选：超时秒数（null 表示使用 Worker 默认值）
 *     public ?int $timeout = null;
 *
 *     // 可选：失败时间戳（null 表示无超时时间限制）
 *     public ?int $timeoutAt = null;
 *
 *     public function handle(Job $job, array $data): void
 *     {
 *         // 处理逻辑...
 *         $job->delete();
 *     }
 *
 *     // 可选：失败回调
 *     public function failed(array $data, \Throwable $e): void
 *     {
 *         // 失败后的清理逻辑
 *     }
 * }
 *
 * // 推送
 * \think\facade\Queue::push(new SendEmail);
 * ```
 */
interface ShouldQueue
{
}