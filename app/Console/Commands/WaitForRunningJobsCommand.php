<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * Used by deploy.sh after `horizon:pause`: first waits until every Horizon supervisor reports the status `paused`, then
 * waits until the reserved set of every queue Horizon works is empty, which means no worker is running a job any more.
 * The first wait is needed because `horizon:pause` only sends a signal: the master and each supervisor apply it on
 * their next one-second loop, and until then workers keep taking jobs, so an empty reserved set proves nothing yet.
 * Without any listed supervisor (Horizon not running) the first wait is skipped. Both waits share one budget. The default timeout of 1900 seconds is the `retry_after` of the
 * `redis-long` connection (config/queue.php), longer than the 1800 second timeout of the imports supervisor, so a
 * healthy import always has time to finish. Exits 1 when jobs are still running at the end, so the deploy stops.
 */
class WaitForRunningJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:wait-for-jobs {--timeout=1900} {--interval=5}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wait until no Horizon worker is running a job';

    public function handle(SupervisorRepository $supervisors): int
    {
        $timeout = max(0, (int) $this->option('timeout'));
        $interval = max(1, (int) $this->option('interval'));
        $maxSleeps = intdiv($timeout, $interval);

        for ($sleeps = 0; $sleeps <= $maxSleeps; $sleeps++) {
            $running = $this->notPausedSupervisors($supervisors);

            if ($running !== []) {
                $names = implode(', ', $running);

                if ($sleeps === $maxSleeps) {
                    $this->error("Horizon did not pause after {$timeout} seconds: {$names}.");

                    return self::FAILURE;
                }

                $this->line("Waiting for Horizon to pause: {$names}");

                Sleep::for($interval)->seconds();

                continue;
            }

            $busy = $this->busyQueues();

            if ($busy === []) {
                $this->info('No jobs are running.');

                return self::SUCCESS;
            }

            if ($sleeps === $maxSleeps) {
                $this->error("Jobs are still running after {$timeout} seconds: {$this->describe($busy)}.");

                return self::FAILURE;
            }

            $this->line("Waiting for running jobs: {$this->describe($busy)}");

            Sleep::for($interval)->seconds();
        }

        return self::FAILURE;
    }

    /**
     * @return array<int, string> names of the supervisors that do not report the status `paused`
     */
    private function notPausedSupervisors(SupervisorRepository $supervisors): array
    {
        $names = [];

        foreach ($supervisors->all() as $supervisor) {
            if (($supervisor->status ?? null) !== 'paused') {
                $names[] = (string) ($supervisor->name ?? 'unknown');
            }
        }

        return $names;
    }

    /**
     * @return array<string, int> queue name => number of running jobs, only for queues with at least one, in config order
     */
    private function busyQueues(): array
    {
        $busy = [];

        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            $connection = Queue::connection($supervisor['connection']);

            foreach ((array) $supervisor['queue'] as $queue) {
                $running = $connection->reservedSize($queue);

                if ($running > 0) {
                    $busy[$queue] = $running;
                }
            }
        }

        return $busy;
    }

    /**
     * @param  array<string, int>  $busy
     */
    private function describe(array $busy): string
    {
        return collect($busy)
            ->map(fn (int $count, string $queue): string => "{$queue} ({$count})")
            ->implode(', ');
    }
}
