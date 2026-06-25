<?php

namespace think\test\queue;

use Mockery as m;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use think\queue\Listener;

class ListenerTest extends TestCase
{
    /** @var Process|MockInterface */
    protected $process;
    /** @var Listener|MockInterface */
    protected $listener;

    public function testRunProcessCallsProcess()
    {
        /** @var Process|MockInterface $process */
        $process = m::mock(Process::class)->makePartial();
        $process->shouldReceive('run')->once();
        /** @var Listener|MockInterface $listener */
        $listener = m::mock(Listener::class)->makePartial();
        $listener->shouldReceive('memoryExceeded')->once()->with(1)->andReturn(false);

        $listener->runProcess($process, 1);

        $this->addToAssertionCount(1);
    }

    public function testListenerStopsWhenMemoryIsExceeded()
    {
        /** @var Process|MockInterface $process */
        $process = m::mock(Process::class)->makePartial();
        $process->shouldReceive('run')->once();
        /** @var Listener|MockInterface $listener */
        $listener = m::mock(Listener::class)->makePartial();
        $listener->shouldReceive('memoryExceeded')->once()->with(1)->andReturn(true);
        $listener->shouldReceive('stop')->once();

        $listener->runProcess($process, 1);

        $this->addToAssertionCount(1);
    }

    public function testMakeProcessCorrectlyFormatsCommandLine()
    {
        $listener = new Listener(__DIR__);

        $process = $listener->makeProcess('connection', 'queue', 1, 3, 0, 2, 3);
        $escape  = '\\' === DIRECTORY_SEPARATOR ? '"' : '\'';

        $this->assertInstanceOf(Process::class, $process);
        $this->assertEquals(__DIR__, $process->getWorkingDirectory());
        $this->assertEquals(3, $process->getTimeout());
        $this->assertEquals($escape . PHP_BINARY . $escape . " {$escape}think{$escape} {$escape}queue:work{$escape} {$escape}connection{$escape} {$escape}--once{$escape} {$escape}--queue=queue{$escape} {$escape}--delay=1{$escape} {$escape}--memory=2{$escape} {$escape}--sleep=3{$escape} {$escape}--tries=0{$escape}", $process->getCommandLine());
    }

    public function testMakeProcessCorrectlyFormatsCommandLineWithAnEnvironmentSpecified()
    {
        $listener = new Listener(__DIR__);

        $process = $listener->makeProcess('connection', 'queue', 1, 3, 0, 2, 3);
        $escape  = '\\' === DIRECTORY_SEPARATOR ? '"' : '\'';

        $this->assertInstanceOf(Process::class, $process);
        $this->assertEquals(__DIR__, $process->getWorkingDirectory());
        $this->assertEquals(3, $process->getTimeout());
        $this->assertEquals($escape . PHP_BINARY . $escape . " {$escape}think{$escape} {$escape}queue:work{$escape} {$escape}connection{$escape} {$escape}--once{$escape} {$escape}--queue=queue{$escape} {$escape}--delay=1{$escape} {$escape}--memory=2{$escape} {$escape}--sleep=3{$escape} {$escape}--tries=0{$escape}", $process->getCommandLine());
    }

    public function testMakeProcessCorrectlyFormatsCommandLineWhenTheConnectionIsNotSpecified()
    {
        $listener = new Listener(__DIR__);

        $process = $listener->makeProcess(null, 'queue', 1, 3, 0, 2, 3);
        $escape  = '\\' === DIRECTORY_SEPARATOR ? '"' : '\'';

        $this->assertInstanceOf(Process::class, $process);
        $this->assertEquals(__DIR__, $process->getWorkingDirectory());
        $this->assertEquals(3, $process->getTimeout());
        $this->assertEquals($escape . PHP_BINARY . $escape . " {$escape}think{$escape} {$escape}queue:work{$escape} {$escape}--once{$escape} {$escape}--queue=queue{$escape} {$escape}--delay=1{$escape} {$escape}--memory=2{$escape} {$escape}--sleep=3{$escape} {$escape}--tries=0{$escape}", $process->getCommandLine());
    }

    public function testMakeProcessSetsWorkingDirectoryAndTimeout()
    {
        $listener = new Listener('/tmp');

        $process = $listener->makeProcess('redis', 'high', 0, 3, 0, 128, 120);

        $this->assertInstanceOf(Process::class, $process);
        $this->assertEquals('/tmp', $process->getWorkingDirectory());
        $this->assertEquals(120, $process->getTimeout());
    }

    public function testOutputHandlerIsInvokedForWorkerOutput()
    {
        $called = false;
        $listener = new Listener(__DIR__);
        $listener->setOutputHandler(function ($type, $line) use (&$called) {
            $called = true;
            $this->assertSame('out', $type);
            $this->assertSame('hello', $line);
        });

        $process = m::mock(Process::class);
        $process->shouldReceive('run')->once()->with(m::type('callable'))
            ->andReturnUsing(function ($callback) {
                $callback('out', 'hello');
                return 0;
            });

        $listener->runProcess($process, 1024);

        $this->assertTrue($called);
    }
}