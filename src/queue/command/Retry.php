<?php

namespace think\queue\command;

use stdClass;
use think\console\Command;
use think\console\input\Argument;
use think\helper\Arr;

class Retry extends Command
{
    protected function configure(): void
    {
        $this->setName('queue:retry')
            ->addArgument('id', Argument::IS_ARRAY | Argument::REQUIRED, 'The ID of the failed job or "all" to retry all jobs')
            ->setDescription('Retry a failed queue job');
    }

    public function handle(): void
    {
        foreach ($this->getJobIds() as $id) {
            $job = $this->app['queue.failer']->find($id);

            if (is_null($job)) {
                $this->output->error("Unable to find failed job with ID [{$id}].");
            } else {
                $this->retryJob($job);

                $this->output->info("The failed job [{$id}] has been pushed back onto the queue!");

                $this->app['queue.failer']->forget($id);
            }
        }
    }

    /**
     * Retry the queue job.
     *
     * @param stdClass|array<string, mixed> $job
     */
    protected function retryJob(object|array $job): void
    {
        $connection = is_array($job) ? $job['connection'] : $job->connection;
        $payload    = is_array($job) ? $job['payload'] : $job->payload;
        $queue      = is_array($job) ? $job['queue'] : $job->queue;

        $this->app['queue']->connection($connection)->pushRaw(
            $this->resetAttempts($payload),
            $queue
        );
    }

    /**
     * Reset the payload attempts.
     *
     * Applicable to Redis jobs which store attempts in their payload.
     */
    protected function resetAttempts(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded) && isset($decoded['attempts'])) {
            $decoded['attempts'] = 0;
            return json_encode($decoded);
        }

        return $payload;
    }

    /**
     * Get the job IDs to be retried.
     *
     * @return array<int, mixed>
     */
    protected function getJobIds(): array
    {
        $ids = (array) $this->input->getArgument('id');

        if (count($ids) === 1 && $ids[0] === 'all') {
            $ids = Arr::pluck($this->app['queue.failer']->all(), 'id');
        }

        return $ids;
    }
}