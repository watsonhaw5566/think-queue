<?php

namespace PHPSTORM_META {

    use think\queue\Connector;
    use think\queue\connector\Database;
    use think\queue\connector\Redis;
    use think\queue\connector\Sync;
    use think\queue\failed\Database as FailedDatabase;
    use think\queue\failed\None as FailedNone;

    /**
     * PhpStorm / IDE 元数据文件
     *
     * 本文件只为静态分析器服务，PHP 运行时不会读取也不会执行其中代码。
     * 它的作用是告诉 IDE：
     *   - `$app->make('queue')` 等字符串绑定实际返回的具体类型
     *   - `\think\Queue::connection('xxx')` 根据连接名字面量推断具体 connector 类型
     *   - 某些字符串参数的常见候选值（例如连接名、队列名）
     *
     * ------------------------------------------------------------------
     * 维护提醒：
     *   当以下内容变化时，需要同步更新本文件：
     *     1. 在 config/queue.php 中新增/删除/重命名了 `connections.*` 的键名
     *        → 改 registerArgumentsSet('queueConnectionNames', ...) 与下方的 map([...])
     *     2. 新增了一个 connector 类（例如 Amqp、Beanstalkd）
     *        → 在 map([...]) 加一行映射，并在 queueConnectionNames 中追加名字
     *     3. 容器绑定名变了（如把 'queue.failer' 改成 'queue.failed'）
     *        → 改 registerArgumentsSet('queueContainerKey', ...) 与相关 expectedReturnValues
     *     4. 某个方法的参数位置发生了变化
     *        → 检查 override(Class::method(N), ...) 中的索引 N 是否仍然正确
     * ------------------------------------------------------------------
     */

    // ========================================================================
    // 1. 容器绑定类型提示
    // ========================================================================

    override(\think\App::make(0), typeInfer(0));
    override(\think\App::bind(0), typeInfer(0));

    registerArgumentsSet(
        'queueContainerKey',
        'queue',
        'queue.failer'
    );

    expectedReturnValues(
        \Psr\Container\ContainerInterface::get(0),
        argumentsSet('queueContainerKey')
    );

    expectedReturnValues(
        \think\App::make(0),
        argumentsSet('queueContainerKey')
    );

    override(
        \think\App::make('queue'),
        map([
            'queue' => \think\Queue::class,
        ])
    );

    override(
        \Psr\Container\ContainerInterface::get('queue'),
        map([
            'queue' => \think\Queue::class,
        ])
    );

    override(
        \think\App::make('queue.failer'),
        map([
            'queue.failer' => FailedDatabase::class . '|' . FailedNone::class,
        ])
    );

    override(
        \Psr\Container\ContainerInterface::get('queue.failer'),
        map([
            'queue.failer' => FailedDatabase::class . '|' . FailedNone::class,
        ])
    );

    // ========================================================================
    // 2. Queue::connection() / Queue::driver() 动态返回类型
    // ========================================================================

    registerArgumentsSet(
        'queueConnectionNames',
        'sync',
        'database',
        'redis'
    );

    expectedReturnValues(
        \think\Queue::connection(0),
        argumentsSet('queueConnectionNames')
    );

    expectedReturnValues(
        \think\Queue::driver(0),
        argumentsSet('queueConnectionNames')
    );

    expectedReturnValues(
        \think\facade\Queue::connection(0),
        argumentsSet('queueConnectionNames')
    );

    override(
        \think\Queue::connection(0),
        map([
            ''         => Connector::class,
            'sync'     => Sync::class,
            'database' => Database::class,
            'redis'    => Redis::class,
        ])
    );

    override(
        \think\Queue::driver(0),
        map([
            ''         => Connector::class,
            'sync'     => Sync::class,
            'database' => Database::class,
            'redis'    => Redis::class,
        ])
    );

    override(
        \think\facade\Queue::connection(0),
        map([
            ''         => Connector::class,
            'sync'     => Sync::class,
            'database' => Database::class,
            'redis'    => Redis::class,
        ])
    );

    // ========================================================================
    // 3. 队列名候选值提示（push/later/pushOn/laterOn 的 $queue 参数）
    // ========================================================================

    registerArgumentsSet(
        'commonQueueNames',
        'default',
        'high',
        'low'
    );

    expectedReturnValues(
        \think\queue\Connector::push(2),
        argumentsSet('commonQueueNames')
    );

    expectedReturnValues(
        \think\queue\Connector::later(3),
        argumentsSet('commonQueueNames')
    );

    expectedReturnValues(
        \think\queue\Connector::pushOn(0),
        argumentsSet('commonQueueNames')
    );

    expectedReturnValues(
        \think\queue\Connector::laterOn(0),
        argumentsSet('commonQueueNames')
    );

    expectedReturnValues(
        \think\queue\Connector::pop(0),
        argumentsSet('commonQueueNames')
    );

    expectedReturnValues(
        \think\facade\Queue::push(2),
        argumentsSet('commonQueueNames')
    );

    expectedReturnValues(
        \think\facade\Queue::later(3),
        argumentsSet('commonQueueNames')
    );

    // ========================================================================
    // 4. Worker 相关参数提示
    // ========================================================================

    expectedReturnValues(
        \think\queue\Worker::daemon(0),
        argumentsSet('queueConnectionNames')
    );

    expectedReturnValues(
        \think\queue\Worker::runNextJob(0),
        argumentsSet('queueConnectionNames')
    );

    expectedReturnValues(
        \think\queue\Worker::daemon(1),
        argumentsSet('commonQueueNames')
    );

    expectedReturnValues(
        \think\queue\Worker::runNextJob(1),
        argumentsSet('commonQueueNames')
    );

    expectedReturnValues(
        \think\queue\Worker::process(1),
        argumentsSet('commonQueueNames')
    );

    // ========================================================================
    // 5. 链式调用：返回 $this 的方法让 IDE 知道这是同一个对象
    // ========================================================================

    override(
        \think\queue\Queueable::onConnection(0),
        type(0)
    );

    override(
        \think\queue\Queueable::onQueue(0),
        type(0)
    );

    override(
        \think\queue\Queueable::delay(0),
        type(0)
    );

    override(
        \think\queue\Connector::setApp(0),
        type(0)
    );

    override(
        \think\queue\Connector::setConnection(0),
        type(0)
    );

    // ========================================================================
    // 6. Listener 相关参数提示
    // ========================================================================

    expectedReturnValues(
        \think\queue\Listener::listen(1),
        argumentsSet('queueConnectionNames')
    );

    expectedReturnValues(
        \think\queue\Listener::listen(2),
        argumentsSet('commonQueueNames')
    );
}