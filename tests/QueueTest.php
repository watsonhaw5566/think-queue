<?php

namespace think\test\queue;

use InvalidArgumentException;
use Mockery as m;
use think\Config;
use think\Queue;
use think\queue\connector\Sync;

class QueueTest extends TestCase
{
    /** @var Queue */
    protected $queue;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testDefaultConnectionCanBeResolved()
    {
        $sync = new Sync();

        $this->app->shouldReceive('invokeClass')->with('\think\queue\connector\Sync', m::any())->andReturn($sync);

        $config = m::mock(Config::class);

        $config->shouldReceive('get')->andReturnUsing(function ($key, $default = null) {
            switch ($key) {
                case 'queue.connections.sync.type':
                    return 'sync';
                case 'queue.connections.sync':
                    return ['type' => 'sync'];
                case 'queue.default':
                    return 'sync';
                default:
                    return $default;
            }
        });

        $this->app->shouldReceive('get')->with('config')->andReturn($config);

        $this->queue = new Queue($this->app);

        $this->assertSame($sync, $this->queue->connection('sync'));
        $this->assertSame($sync, $this->queue->connection());
    }

    public function testNotSupportDriver()
    {
        $config = m::mock(Config::class);

        $config->shouldReceive('get')->andReturnUsing(function ($key, $default = null) {
            switch ($key) {
                case 'queue.connections.hello.type':
                    return 'hello';
                case 'queue.connections.hello':
                    return ['type' => 'hello'];
                default:
                    return $default;
            }
        });

        $this->app->shouldReceive('get')->with('config')->andReturn($config);

        $this->queue = new Queue($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->queue->connection('hello');
    }
}