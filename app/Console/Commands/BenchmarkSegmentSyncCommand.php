<?php

namespace App\Console\Commands;

use App\Jobs\SyncSegmentMembership;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Segment;
use App\Support\Benchmark\BenchmarkDatabase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Times the membership sync of every published segment of the benchmark organization, and prints the planner
 * statistics of the main tables and, with `--explain`, the plan of each segment's matching query. It runs only on the
 * benchmark database (see `BenchmarkDatabase`), and the guard is its first statement: no query goes out before it.
 */
class BenchmarkSegmentSyncCommand extends Command
{
    private const array STATISTICS_TABLES = ['contacts', 'companies', 'company_contact', 'contact_tag', 'contact_activities', 'deals', 'contact_segment'];

    protected $signature = 'bench:segment-sync
        {--analyze : Run ANALYZE on the benchmark database before timing}
        {--explain : Print the EXPLAIN (ANALYZE, BUFFERS) plan of each segment\'s matching query}';

    protected $description = 'Time the membership sync of every published segment of the benchmark organization (benchmark database only)';

    public function handle(): int
    {
        if (! BenchmarkDatabase::isSafeTarget()) {
            $this->error('Refusing to run the segment sync benchmark: it runs only with --env=benchmark on the srw-crm-bench database with sync queues (found '.BenchmarkDatabase::describeTarget().'). Run php artisan config:clear and check .env.benchmark.');

            return self::FAILURE;
        }

        $organization = Organization::query()->whereRelation('owner', 'email', BenchmarkDatabase::OWNER_EMAIL)->first();

        if (! $organization) {
            $this->error('No benchmark organization found (owner '.BenchmarkDatabase::OWNER_EMAIL.'). Seed it first with php artisan --env=benchmark db:seed --class=VolumeBenchmarkSeeder --no-interaction.');

            return self::FAILURE;
        }

        app(TenantContext::class)->run($organization->id, function () use ($organization): void {
            $this->runBenchmark($organization);
        });

        return self::SUCCESS;
    }

    private function runBenchmark(Organization $organization): void
    {
        $contacts = Contact::query()->count();
        $companies = Company::query()->count();
        $deals = Deal::query()->count();
        $activities = Activity::query()->count();

        $this->line("{$organization->name} (#{$organization->id}): {$contacts} contacts, {$companies} companies, {$deals} deals, {$activities} activities.");

        if ($this->option('analyze')) {
            $start = hrtime(true);
            DB::statement('ANALYZE');
            $this->line('ANALYZE done in '.(int) round((hrtime(true) - $start) / 1e6).' ms.');
        }

        $this->table(['Table', 'Live rows', 'Changed since analyze', 'Last analyzed (UTC)'], $this->statisticsRows());

        $segments = Segment::query()->where('is_published', true)->orderBy('id')->get();

        $totalStart = hrtime(true);
        $rows = $segments->map(fn (Segment $segment): array => $this->timeSync($segment))->all();
        $totalMs = (int) round((hrtime(true) - $totalStart) / 1e6);

        $this->table(['Segment', 'Members', 'Total ms', 'Slowest statement ms', 'Statements', 'Slowest statement'], $rows);
        $this->line("Synced {$segments->count()} segments in {$totalMs} ms.");

        if ($this->option('explain')) {
            $segments->each(fn (Segment $segment) => $this->printPlan($segment));
        }
    }

    /**
     * @return array<int, array{string, int, string, string}>
     */
    private function statisticsRows(): array
    {
        return DB::table('pg_stat_user_tables')
            ->where('schemaname', 'public')
            ->whereIn('relname', self::STATISTICS_TABLES)
            ->get(['relname', 'n_live_tup', 'n_mod_since_analyze', 'last_analyze', 'last_autoanalyze'])
            ->sortBy(fn (object $row): int => (int) array_search($row->relname, self::STATISTICS_TABLES, true))
            ->map(fn (object $row): array => [
                $row->relname,
                (int) $row->n_live_tup,
                (int) $row->n_mod_since_analyze,
                $this->lastAnalyzed($row->last_analyze, $row->last_autoanalyze),
            ])
            ->values()
            ->all();
    }

    private function lastAnalyzed(?string $manual, ?string $automatic): string
    {
        $times = collect([$manual, $automatic])
            ->filter()
            ->map(fn (string $time): Carbon => Carbon::parse($time)->utc());

        if ($times->isEmpty()) {
            return 'never';
        }

        return $times->max()->format('Y-m-d H:i:s');
    }

    /**
     * Runs the job's handler directly (no queue, no cache lock, no notification) and reads the statements it ran
     * from the query log.
     *
     * @return array{string, int, int, int, int, string}
     */
    private function timeSync(Segment $segment): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);

        (new SyncSegmentMembership($segment->id))->handle();

        $ms = (hrtime(true) - $start) / 1e6;
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        $slowest = $this->slowestStatement($log);

        return [
            $segment->name,
            $segment->contacts()->count(),
            (int) round($ms),
            (int) round($slowest['time']),
            $log->count(),
            Str::limit((string) preg_replace('/\s+/', ' ', $slowest['query']), 80),
        ];
    }

    /**
     * @param  Collection<int, array{query: string, bindings: array<int, mixed>, time: float|null}>  $log
     * @return array{query: string, time: float}
     */
    private function slowestStatement(Collection $log): array
    {
        $slowest = ['query' => '', 'time' => 0.0];

        foreach ($log as $entry) {
            if ((float) $entry['time'] > $slowest['time'] || $slowest['query'] === '') {
                $slowest = ['query' => $entry['query'], 'time' => (float) $entry['time']];
            }
        }

        return $slowest;
    }

    private function printPlan(Segment $segment): void
    {
        $matching = $segment->queryBuilder()
            ->matching($segment->publishedRules())
            ->select('contacts.id');

        $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$matching->toSql(), $matching->getBindings());

        $this->newLine();
        $this->line("EXPLAIN ANALYZE {$segment->name}:");

        foreach ($plan as $row) {
            $this->line($row->{'QUERY PLAN'});
        }
    }
}
