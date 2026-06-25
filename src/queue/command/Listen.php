<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\queue\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\queue\Listener;

class Listen extends Command
{
    protected Listener $listener;

    public function __construct(Listener $listener)
    {
        parent::__construct();
        $this->listener = $listener;
        $this->listener->setOutputHandler(function (string $type, string $line): void {
            $this->output->write($line);
        });
    }

    protected function configure(): void
    {
        $this->setName('queue:listen')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work', null)
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'The queue to listen on', null)
            ->addOption('delay', null, Option::VALUE_OPTIONAL, 'Amount of time to delay failed jobs', 0)
            ->addOption('memory', null, Option::VALUE_OPTIONAL, 'The memory limit in megabytes', 128)
            ->addOption('timeout', null, Option::VALUE_OPTIONAL, 'Seconds a job may run before timing out', 60)
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Seconds to wait before checking queue for jobs', 3)
            ->addOption('tries', null, Option::VALUE_OPTIONAL, 'Number of times to attempt a job before logging it failed', 0)
            ->setDescription('Listen to a given queue');
    }

    public function execute(Input $input, Output $output): void
    {
        $connection = (string) ($input->getArgument('connection') ?: $this->app->config->get('queue.default'));

        $queue   = (string) ($input->getOption('queue') ?: $this->app->config->get("queue.connections.{$connection}.queue", 'default'));
        $delay   = (int) $input->getOption('delay');
        $memory  = (int) $input->getOption('memory');
        $timeout = (int) $input->getOption('timeout');
        $sleep   = (int) $input->getOption('sleep');
        $tries   = (int) $input->getOption('tries');

        $this->listener->listen($connection, $queue, $delay, $sleep, $tries, $memory, $timeout);
    }
}