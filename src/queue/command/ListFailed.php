<?php

namespace think\queue\command;

use think\console\Command;
use think\console\Table;
use think\helper\Arr;

class ListFailed extends Command
{
    /**
     * The table headers for the command.
     *
     * @var array<int, string>
     */
    protected array $headers = ['ID', 'Connection', 'Queue', 'Class', 'Fail Time'];

    protected function configure(): void
    {
        $this->setName('queue:failed')
            ->setDescription('List all of the failed queue jobs');
    }

    public function handle(): void
    {
        if (count($jobs = $this->getFailedJobs()) === 0) {
            $this->output->info('No failed jobs!');
            return;
        }
        $this->displayFailedJobs($jobs);
    }

    /**
     * Display the failed jobs in the console.
     *
     * @param array<int, array<int, mixed>> $jobs
     */
    protected function displayFailedJobs(array $jobs): void
    {
        $table = new Table();
        $table->setHeader($this->headers);
        $table->setRows($jobs);

        $this->table($table);
    }

    /**
     * Compile the failed jobs into a displayable format.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function getFailedJobs(): array
    {
        $failed = $this->app['queue.failer']->all();

        return collect($failed)->map(function (mixed $failed): array {
            return $this->parseFailedJob((array) $failed);
        })->filter()->all();
    }

    /**
     * Parse the failed job row.
     *
     * @param array<string, mixed> $failed
     * @return array<int, mixed>
     */
    protected function parseFailedJob(array $failed): array
    {
        $row = array_values(Arr::except($failed, ['payload', 'exception']));

        array_splice($row, 3, 0, $this->extractJobName((string) $failed['payload']));

        return $row;
    }

    /**
     * Extract the failed job name from payload.
     */
    private function extractJobName(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        if ($decoded && (!isset($decoded['data']['command']))) {
            return $decoded['job'] ?? null;
        } elseif ($decoded && isset($decoded['data']['command'])) {
            return $this->matchJobName($decoded);
        }

        return null;
    }

    /**
     * Match the job name from the payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function matchJobName(array $payload): ?string
    {
        preg_match('/"([^"]+)"/', (string) $payload['data']['command'], $matches);

        if (isset($matches[1])) {
            return $matches[1];
        }

        return $payload['job'] ?? null;
    }
}