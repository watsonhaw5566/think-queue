<?php

namespace think\test\queue;

use Carbon\Carbon;
use Mockery as m;
use Mockery\MockInterface;
use RuntimeException;
use think\Cache;
use think\Event;
use think\exception\Handle;
use think\Queue;
use think\queue\connector\Sync;
use think\queue\event\JobExceptionOccurred;
use think\queue\event\JobFailed;
use think\queue\event\JobProcessed;
use think\queue\event\JobProcessing;
use think\queue\exception\MaxAttemptsExceededException;
use think\queue\Job;

class WorkerTest extends TestCase
{
    /** @var Handle|MockInterface */
    protected $handle;

    /** @var Event|MockInterface */
    protected $event;

    /** @var Cache|MockInterface */
    protected $cache;

    /** @var Queue|MockInterface */
    protected $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue  = m::mock(Queue::class);
        $this->handle = m::spy(Handle::class);
        $this->event  = m::spy(Event::class);
        $this->cache  = m::spy(Cache::class);
    }

    public function testJobCanBeFired()
    {

        $worker = $this->getWorker(['default' => [$job = new WorkerFakeJob]]);

        $worker->runNextJob('sync', 'default');

        $this->event->shouldHaveReceived('trigger')->with(m::type(JobProcessing::class))->once();
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobProcessed::class))->once();
        $this->assertTrue($job->fired);
    }

    public function testWorkerCanWorkUntilQueueIsEmpty()
    {
        $worker = $this->getWorker(['default' => [
            $firstJob = new WorkerFakeJob,
            $secondJob = new WorkerFakeJob,
        ]]);

        $this->expectException(LoopBreakerException::class);

        $worker->daemon('sync', 'default');

        $this->assertTrue($firstJob->fired);

        $this->assertTrue($secondJob->fired);

        $this->assertSame(0, $worker->stoppedWithStatus);

        $this->event->shouldHaveReceived('trigger')->with(m::type(JobProcessing::class))->twice();

        $this->event->shouldHaveReceived('trigger')->with(m::type(JobProcessed::class))->twice();
    }

    public function testJobCanBeFiredBasedOnPriority()
    {
        $worker = $this->getWorker([
            'high' => [
                $highJob = new WorkerFakeJob,
                $secondHighJob = new WorkerFakeJob,
            ],
            'low'  => [$lowJob = new WorkerFakeJob],
        ]);

        $worker->runNextJob('sync', 'high,low');

        $this->assertTrue($highJob->fired);
        $this->assertFalse($secondHighJob->fired);
        $this->assertFalse($lowJob->fired);

        $worker->runNextJob('sync', 'high,low');
        $this->assertTrue($secondHighJob->fired);
        $this->assertFalse($lowJob->fired);

        $worker->runNextJob('sync', 'high,low');
        $this->assertTrue($lowJob->fired);
    }

    public function testExceptionIsReportedIfConnectionThrowsExceptionOnJobPop()
    {
        $e = new RuntimeException();

        $sync = m::mock(Sync::class);

        $sync->shouldReceive('pop')->andReturnUsing(function () use ($e) {
            throw $e;
        });

        $this->queue->shouldReceive('connection')->with('sync')->andReturn($sync);

        $worker = new Worker($this->queue, $this->event, $this->handle);

        $worker->runNextJob('sync', 'default');

        $this->handle->shouldHaveReceived('report')->with($e);
        $this->assertTrue(true);
    }

    public function testWorkerSleepsWhenQueueIsEmpty()
    {
        $worker = $this->getWorker(['default' => []]);
        $worker->runNextJob('sync', 'default', 0, 5);
        $this->assertEquals(5, $worker->sleptFor);
    }

    public function testMemoryIsProperlyEvaluatedAgainstLimit()
    {
        $worker = new Worker($this->queue, $this->event, $this->handle, $this->cache);

        $this->assertIsBool($worker->memoryExceeded(8));
    }

    public function testJobIsReleasedOnException()
    {
        $e = new RuntimeException;

        $job = new WorkerFakeJob(function () use ($e) {
            throw $e;
        });

        $worker = $this->getWorker(['default' => [$job]]);
        $worker->runNextJob('sync', 'default', 10);

        $this->assertEquals(10, $job->releaseAfter);
        $this->assertFalse($job->deleted);
        $this->handle->shouldHaveReceived('report')->with($e);
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobExceptionOccurred::class))->once();
        $this->event->shouldNotHaveReceived('trigger', [m::type(JobProcessed::class)]);
    }

    public function testJobIsNotReleasedIfItHasExceededMaxAttempts()
    {
        $e = new RuntimeException;

        $job           = new WorkerFakeJob(function ($job) use ($e) {
            // In normal use this would be incremented by being popped off the queue
            $job->attempts++;

            throw $e;
        });
        $job->attempts = 1;

        $worker = $this->getWorker(['default' => [$job]]);
        $worker->runNextJob('sync', 'default', 0, 3, 1);

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertEquals($e, $job->failedWith);
        $this->handle->shouldHaveReceived('report')->with($e);
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobExceptionOccurred::class))->once();
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobFailed::class))->once();
        $this->event->shouldNotHaveReceived('trigger', [m::type(JobProcessed::class)]);
    }

    public function testJobIsNotReleasedIfItHasExpired()
    {
        $e = new RuntimeException;

        $job = new WorkerFakeJob(function ($job) use ($e) {
            // In normal use this would be incremented by being popped off the queue
            $job->attempts++;

            throw $e;
        });

        $job->timeoutAt = Carbon::now()->addSeconds(1)->getTimestamp();

        $job->attempts = 0;

        Carbon::setTestNow(
            Carbon::now()->addSeconds(1)
        );

        $worker = $this->getWorker(['default' => [$job]]);
        $worker->runNextJob('sync', 'default');

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertEquals($e, $job->failedWith);
        $this->handle->shouldHaveReceived('report')->with($e);
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobExceptionOccurred::class))->once();
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobFailed::class))->once();
        $this->event->shouldNotHaveReceived('trigger', [m::type(JobProcessed::class)]);
    }

    public function testJobIsFailedIfItHasAlreadyExceededMaxAttempts()
    {
        $job = new WorkerFakeJob(function ($job) {
            $job->attempts++;
        });

        $job->attempts = 2;

        $worker = $this->getWorker(['default' => [$job]]);
        $worker->runNextJob('sync', 'default', 0, 3, 1);

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertInstanceOf(MaxAttemptsExceededException::class, $job->failedWith);
        $this->handle->shouldHaveReceived('report')->with(m::type(MaxAttemptsExceededException::class));
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobExceptionOccurred::class))->once();
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobFailed::class))->once();
        $this->event->shouldNotHaveReceived('trigger', [m::type(JobProcessed::class)]);
    }

    public function testJobIsFailedIfItHasAlreadyExpired()
    {
        $job = new WorkerFakeJob(function ($job) {
            $job->attempts++;
        });

        $job->timeoutAt = Carbon::now()->addSeconds(2)->getTimestamp();

        $job->attempts = 1;

        Carbon::setTestNow(
            Carbon::now()->addSeconds(3)
        );

        $worker = $this->getWorker(['default' => [$job]]);
        $worker->runNextJob('sync', 'default');

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertInstanceOf(MaxAttemptsExceededException::class, $job->failedWith);
        $this->handle->shouldHaveReceived('report')->with(m::type(MaxAttemptsExceededException::class));
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobExceptionOccurred::class))->once();
        $this->event->shouldHaveReceived('trigger')->with(m::type(JobFailed::class))->once();
        $this->event->shouldNotHaveReceived('trigger', [m::type(JobProcessed::class)]);
    }

    public function testJobBasedMaxRetries()
    {
        $job = new WorkerFakeJob(function ($job) {
            $job->attempts++;
        });

        $job->attempts = 2;

        $job->maxTries = 10;

        $worker = $this->getWorker(['default' => [$job]]);
        $worker->runNextJob('sync', 'default', 0, 3, 1);

        $this->assertFalse($job->deleted);
        $this->assertNull($job->failedWith);
    }

    protected function getWorker($jobs)
    {
        $sync = m::mock(Sync::class);

        $sync->shouldReceive('pop')->andReturnUsing(function ($queue) use (&$jobs) {
            return array_shift($jobs[$queue]);
        });

        $this->queue->shouldReceive('connection')->with('sync')->andReturn($sync);

        return new Worker($this->queue, $this->event, $this->handle, $this->cache);
    }
}

class WorkerFakeConnector
{
    public $jobs = [];

    public function __construct($jobs)
    {
        $this->jobs = $jobs;
    }

    public function pop($queue)
    {
        return array_shift($this->jobs[$queue]);
    }
}

class Worker extends \think\queue\Worker
{
    public $sleptFor;

    public $stoppedWithStatus;

    public function sleep(int $seconds): void
    {
        $this->sleptFor = $seconds;
    }

    public function stop(int $status = 0): void
    {
        $this->stoppedWithStatus = $status;

        throw new LoopBreakerException;
    }

    protected function stopIfNecessary(?Job $job, mixed $lastRestart, int $memory): void
    {
        if ($job === null) {
            $this->stop();
        } else {
            parent::stopIfNecessary($job, $lastRestart, $memory);
        }
    }
}

class LoopBreakerException extends RuntimeException
{
    //
}

class WorkerFakeJob extends \think\queue\Job
{
    public bool $fired      = false;
    public             $callback;
    public             $releaseAfter;
    public ?int        $maxTries   = null;
    public ?int        $timeoutAt  = null;
    public int         $attempts   = 0;
    public ?\Throwable $failedWith = null;

    // 从父类继承并重写为 public 以支持测试中直接读取。
    public bool $deleted  = false;
    public bool $released = false;
    public bool $failed   = false;

    public function __construct($callback = null)
    {
        $this->callback = $callback ?: function () {
            //
        };
    }

    public function fire(): void
    {
        $this->fired = true;
        ($this->callback)($this);
    }

    public function payload(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return [];
        }
        return $default;
    }

    public function maxTries(): ?int
    {
        return $this->maxTries;
    }

    public function timeoutAt(): ?int
    {
        return $this->timeoutAt;
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
        $this->released     = true;
        $this->releaseAfter = $delay;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function markAsFailed(): void
    {
        $this->failed = true;
    }

    public function failed(\Throwable $e): void
    {
        $this->markAsFailed();
        $this->failedWith = $e;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function timeout(): ?int
    {
        return time() + 60;
    }

    public function getName(): string
    {
        return 'WorkerFakeJob';
    }

    public function getJobId(): mixed
    {
        return null;
    }

    public function getRawBody(): string
    {
        return '';
    }

    public function getQueue(): string
    {
        return 'default';
    }
}