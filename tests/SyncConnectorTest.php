<?php

namespace think\test\queue;

use Mockery as m;
use Mockery\MockInterface;
use think\Event;
use think\queue\connector\Sync;
use think\queue\event\JobFailed;
use think\queue\event\JobProcessed;
use think\queue\event\JobProcessing;

class SyncConnectorTest extends TestCase
{
    /** @var Sync */
    protected $connector;

    /** @var Event|MockInterface */
    protected $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = m::spy(Event::class);
        $this->app->shouldReceive('get')->with('event')->andReturn($this->event);
        $this->app->shouldReceive('getNamespace')->andReturn('app\\');

        $this->connector = new Sync();
        $this->connector->setApp($this->app)->setConnection('sync');
    }

    public function testSizeAlwaysReturnsZero()
    {
        $this->assertSame(0, $this->connector->size());
        $this->assertSame(0, $this->connector->size('any-queue'));
    }

    public function testPopReturnsNull()
    {
        $this->assertNull($this->connector->pop());
    }

    public function testPushRawReturnsNull()
    {
        $this->assertNull($this->connector->pushRaw(json_encode(['job' => 'foo', 'data' => []])));
    }

    public function testLaterDelegatesToPush()
    {
        $connector = m::mock(Sync::class)->makePartial();
        $connector->setApp($this->app)->setConnection('sync');
        $connector->shouldReceive('push')->once()->with('foo', ['data'], 'default')->andReturn(0);

        $this->assertSame(0, $connector->later(60, 'foo', ['data'], 'default'));
    }

    public function testPushTriggersProcessingAndProcessedEvents()
    {
        $this->app->shouldReceive('make')->with(m::type('string'), m::any(), true)
            ->andReturn(new SyncFakeJob());

        $this->connector->push('FakeJob', ['data'], 'default');

        $this->event->shouldHaveReceived('trigger')->with(m::type(JobProcessing::class))->once();
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobProcessed::class))->once();
        $this->assertTrue(true);
    }

    public function testPushTriggersJobFailedWhenJobThrows()
    {
        $this->app->shouldReceive('make')->with(m::type('string'), m::any(), true)
            ->andReturn(new SyncFailingJob());

        try {
            $this->connector->push('FailingJob', [], 'default');
            $this->fail('Expected exception was not thrown');
        } catch (\Throwable $e) {
            $this->assertSame('bang', $e->getMessage());
        }

        $this->event->shouldHaveReceived('trigger')->with(m::type(JobFailed::class))->once();
    }
}

class SyncFakeJob
{
    public $fired = false;

    public function fire($job, $data)
    {
        $this->fired = true;
    }
}

class SyncFailingJob
{
    public function fire($job, $data)
    {
        throw new \RuntimeException('bang');
    }
}