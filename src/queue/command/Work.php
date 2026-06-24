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
namespace think\queue\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\queue\event\JobFailed;
use think\queue\event\JobProcessed;
use think\queue\event\JobProcessing;
use think\queue\Job;
use think\queue\Worker;

class Work extends Command
{
    protected Worker $worker;

    public function __construct(Worker $worker)
    {
        parent::__construct();
        $this->worker = $worker;
    }

    protected function configure(): void
    {
        $this->setName('queue:work')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work', null)
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'The queue to listen on')
            ->addOption('once', null, Option::VALUE_NONE, 'Only process the next job on the queue')
            ->addOption('delay', null, Option::VALUE_OPTIONAL, 'Amount of time to delay failed jobs', 0)
            ->addOption('force', null, Option::VALUE_NONE, 'Force the worker to run even in maintenance mode')
            ->addOption('memory', null, Option::VALUE_OPTIONAL, 'The memory limit in megabytes', 128)
            ->addOption('timeout', null, Option::VALUE_OPTIONAL, 'The number of seconds a child process can run', 60)
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Number of seconds to sleep when no job is available', 3)
            ->addOption('tries', null, Option::VALUE_OPTIONAL, 'Number of times to attempt a job before logging it failed', 0)
            ->setDescription('Process the next job on a queue');
    }

    public function execute(Input $input, Output $output): ?int
    {
        $connection = (string) ($input->getArgument('connection') ?: $this->app->config->get('queue.default', 'sync'));

        $queue = (string) ($input->getOption('queue')
            ?: $this->app->config->get("queue.connections.{$connection}.queue", 'default'));

        $delay = (int) $input->getOption('delay');
        $sleep = (int) $input->getOption('sleep');
        $tries = (int) $input->getOption('tries');

        $this->listenForEvents();

        if ($input->getOption('once')) {
            $this->worker->runNextJob($connection, $queue, $delay, $sleep, $tries);
            return 0;
        }

        $memory  = (int) $input->getOption('memory');
        $timeout = (int) $input->getOption('timeout');

        $this->worker->daemon($connection, $queue, $delay, $sleep, $tries, $memory, $timeout);

        return 0;
    }

    protected function listenForEvents(): void
    {
        $this->app->event->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->writeOutput($event->job, 'starting');
        });

        $this->app->event->listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->writeOutput($event->job, 'success');
        });

        $this->app->event->listen(JobFailed::class, function (JobFailed $event): void {
            $this->writeOutput($event->job, 'failed');

            $this->logFailedJob($event);
        });
    }

    /**
     * @param 'starting'|'success'|'failed' $status
     */
    protected function writeOutput(Job $job, string $status): void
    {
        match ($status) {
            'starting' => $this->writeStatus($job, 'Processing', 'comment'),
            'success'  => $this->writeStatus($job, 'Processed', 'info'),
            'failed'   => $this->writeStatus($job, 'Failed', 'error'),
        };
    }

    protected function writeStatus(Job $job, string $status, string $type): void
    {
        $this->output->writeln(sprintf(
            "<{$type}>[%s][%s] %s</{$type}> %s",
            date('Y-m-d H:i:s'),
            (string) $job->getJobId(),
            str_pad("{$status}:", 11),
            $job->getName()
        ));
    }

    protected function logFailedJob(JobFailed $event): void
    {
        $failer = $this->app['queue.failer'];

        if (is_object($failer) && method_exists($failer, 'log')) {
            $failer->log(
                $event->connection,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception
            );
        }
    }
}