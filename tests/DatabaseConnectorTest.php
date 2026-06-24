<?php

namespace think\test\queue;

use Carbon\Carbon;
use Mockery as m;
use Mockery\MockInterface;
use ReflectionClass;
use stdClass;
use think\Db;
use think\db\ConnectionInterface;
use think\queue\Connector;
use think\queue\connector\Database;
use think\queue\job\Database as DatabaseJob;

class DatabaseConnectorTest extends TestCase
{
    /** @var Database|MockInterface */
    protected $connector;

    /** @var Db|MockInterface */
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db        = m::mock(ConnectionInterface::class);
        $this->connector = new Database($this->db, 'table', 'default');
    }

    public function testPushProperlyPushesJobOntoDatabase()
    {
        $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));

        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) {
            $this->assertEquals('default', $array['queue']);
            $this->assertEquals(json_encode(['job' => 'foo', 'maxTries' => null, 'timeout' => null, 'data' => ['data']]), $array['payload']);
            $this->assertEquals(0, $array['attempts']);
            $this->assertNull($array['reserve_time']);
            $this->assertIsInt($array['available_time']);
        });
        $this->connector->push('foo', ['data']);
    }

    public function testDelayedPushProperlyPushesJobOntoDatabase()
    {
        $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));

        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) {
            $this->assertEquals('default', $array['queue']);
            $this->assertEquals(json_encode(['job' => 'foo', 'maxTries' => null, 'timeout' => null, 'data' => ['data']]), $array['payload']);
            $this->assertEquals(0, $array['attempts']);
            $this->assertNull($array['reserve_time']);
            $this->assertIsInt($array['available_time']);
        });

        $this->connector->later(10, 'foo', ['data']);
    }

    public function testFailureToCreatePayloadFromObject()
    {
        $this->expectException('InvalidArgumentException');

        $job          = new stdClass;
        $job->invalid = "\xc3\x28";

        $queue = $this->getMockForAbstractClass(Connector::class);
        $class = new ReflectionClass(Connector::class);

        $createPayload = $class->getMethod('createPayload');
        $createPayload->setAccessible(true);
        $createPayload->invokeArgs($queue, [
            $job,
            'queue-name',
        ]);
    }

    public function testFailureToCreatePayloadFromArray()
    {
        $this->expectException('InvalidArgumentException');

        $queue = $this->getMockForAbstractClass(Connector::class);
        $class = new ReflectionClass(Connector::class);

        $createPayload = $class->getMethod('createPayload');
        $createPayload->setAccessible(true);
        $createPayload->invokeArgs($queue, [
            'some-job',
            ["key" => "\xc3\x28"],
        ]);
    }

    public function testBulkBatchPushesOntoDatabase()
    {

        $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));

        Carbon::setTestNow(
            $now = Carbon::now()->addSeconds()
        );

        $query->shouldReceive('insertAll')->once()->andReturnUsing(function ($records) use ($now) {
            $this->assertEquals([
                [
                    'queue'          => 'queue',
                    'payload'        => json_encode(['job' => 'foo', 'maxTries' => null, 'timeout' => null, 'data' => ['data']]),
                    'attempts'       => 0,
                    'reserve_time'   => null,
                    'available_time' => $now->getTimestamp(),
                    'create_time'    => $now->getTimestamp(),
                ], [
                    'queue'          => 'queue',
                    'payload'        => json_encode(['job' => 'bar', 'maxTries' => null, 'timeout' => null, 'data' => ['data']]),
                    'attempts'       => 0,
                    'reserve_time'   => null,
                    'available_time' => $now->getTimestamp(),
                    'create_time'    => $now->getTimestamp(),
                ],
            ], $records);
        });

        $this->connector->bulk(['foo', 'bar'], ['data'], 'queue');
    }

    public function testSizeCallsCountOnNamedConnection()
    {
        $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));
        $query->shouldReceive('where')->with('queue', 'default')->andReturnSelf();
        $query->shouldReceive('count')->once()->andReturn(5);

        $this->assertSame(5, $this->connector->size());
    }

    public function testSizeWithCustomQueue()
    {
        $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));
        $query->shouldReceive('where')->with('queue', 'custom')->andReturnSelf();
        $query->shouldReceive('count')->once()->andReturn(12);

        $this->assertSame(12, $this->connector->size('custom'));
    }

    public function testPushRawCallsInsertGetId()
    {
        $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));
        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) {
            $this->assertEquals('default', $array['queue']);
            $this->assertEquals('raw-payload', $array['payload']);
            $this->assertEquals(0, $array['attempts']);
            $this->assertNull($array['reserve_time']);
        });

        $this->connector->pushRaw('raw-payload');
    }

    public function testReleaseReleasesJobBackOntoDatabase()
    {
        $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));

        $job           = new stdClass;
        $job->payload  = json_encode(['job' => 'foo', 'data' => []]);
        $job->attempts = 1;

        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) {
            $this->assertEquals('default', $array['queue']);
            $this->assertEquals(1, $array['attempts']);
            $this->assertNull($array['reserve_time']);
        });

        $this->connector->release('default', $job, 10);
    }

    public function testPopReturnsDatabaseJobWhenAvailable()
    {
        $jobRow = [
            'id'             => 1,
            'queue'          => 'default',
            'payload'        => json_encode(['job' => 'foo', 'data' => []]),
            'attempts'       => 0,
            'reserve_time'   => null,
            'available_time' => Carbon::now()->subSecond()->getTimestamp(),
            'create_time'    => Carbon::now()->subSecond()->getTimestamp(),
        ];

        $this->db->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($jobRow) {
            $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));
            $query->shouldReceive('lock')->andReturnSelf();
            $query->shouldReceive('where')->andReturnSelf();
            $query->shouldReceive('order')->andReturnSelf();
            $query->shouldReceive('find')->andReturn($jobRow);
            $query->shouldReceive('update');

            return $callback();
        });

        $this->connector->setApp($this->app);

        $result = $this->connector->pop();
        $this->assertInstanceOf(DatabaseJob::class, $result);
    }

    public function testPopReturnsNullWhenNoJobsAvailable()
    {
        $this->db->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));
            $query->shouldReceive('lock')->andReturnSelf();
            $query->shouldReceive('where')->andReturnSelf();
            $query->shouldReceive('order')->andReturnSelf();
            $query->shouldReceive('find')->andReturn(null);

            return $callback();
        });

        $this->assertNull($this->connector->pop());
    }

    public function testDeleteReservedRunsInTransaction()
    {
        $this->db->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            $this->db->shouldReceive('name')->with('table')->andReturn($query = m::mock(stdClass::class));
            $query->shouldReceive('lock')->with(true)->andReturnSelf();
            $query->shouldReceive('find')->with(42)->andReturn(['id' => 42]);
            $query->shouldReceive('where')->with('id', 42)->andReturnSelf();
            $query->shouldReceive('delete')->once();

            return $callback();
        });

        $this->connector->deleteReserved(42);
        $this->addToAssertionCount(1);
    }

    public function testSetAndGetConnection()
    {
        $this->assertSame($this->connector, $this->connector->setConnection('redis'));
        $this->assertSame('redis', $this->connector->getConnection());
    }

    public function testCreateObjectPayloadAddsCallQueuedHandler()
    {
        $queue = $this->getMockForAbstractClass(Connector::class);
        $class = new ReflectionClass(Connector::class);

        $createPayload = $class->getMethod('createPayloadArray');
        $createPayload->setAccessible(true);

        $obj       = new stdClass;
        $obj->data = 'hello';

        $result = $createPayload->invokeArgs($queue, [$obj]);

        $this->assertSame('think\queue\CallQueuedHandler@call', $result['job']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('commandName', $result['data']);
        $this->assertArrayHasKey('command', $result['data']);
    }

    public function testCreatePlainPayload()
    {
        $queue = $this->getMockForAbstractClass(Connector::class);
        $class = new ReflectionClass(Connector::class);

        $createPayload = $class->getMethod('createPayloadArray');
        $createPayload->setAccessible(true);

        $result = $createPayload->invokeArgs($queue, ['myJob', ['key' => 'value']]);

        $this->assertSame('myJob', $result['job']);
        $this->assertSame(['key' => 'value'], $result['data']);
        $this->assertNull($result['maxTries']);
        $this->assertNull($result['timeout']);
    }

    public function testSetMetaOnPayload()
    {
        $queue = $this->getMockForAbstractClass(Connector::class);
        $class = new ReflectionClass(Connector::class);

        $setMeta = $class->getMethod('setMeta');
        $setMeta->setAccessible(true);

        $original = json_encode(['job' => 'foo', 'data' => []]);
        $result   = $setMeta->invokeArgs($queue, [$original, 'attempts', 3]);

        $this->assertSame(3, json_decode($result, true)['attempts']);
    }

    public function testGetJobExpiration()
    {
        $queue = $this->getMockForAbstractClass(Connector::class);

        $obj = new stdClass;
        $this->assertNull($queue->getJobExpiration($obj));

        $obj2            = new stdClass;
        $obj2->timeoutAt = 1234567890;
        $this->assertSame(1234567890, $queue->getJobExpiration($obj2));
    }
}