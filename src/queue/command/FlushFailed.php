<?php

namespace think\queue\command;

use think\console\Command;

class FlushFailed extends Command
{
    protected function configure(): void
    {
        $this->setName('queue:flush')
            ->setDescription('Flush all of the failed queue jobs');
    }

    public function handle(): void
    {
        $this->app->get('queue.failer')->flush();

        $this->output->info('All failed jobs deleted successfully!');
    }
}