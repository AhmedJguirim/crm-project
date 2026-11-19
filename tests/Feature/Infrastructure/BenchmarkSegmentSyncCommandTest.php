<?php

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Segment;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\VolumeBenchmarkSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Runs the benchmark command and returns its exit code and output.
 *
 * @return array{int, string}
 */
function runSegmentSyncBenchmark(string $options = ''): array
{
    $code = Artisan::call(trim("bench:segment-sync {$options}"));

    return [$code, Artisan::output()];
}

/**
 * The refusal line the command prints for a given target.
 */
function segmentSyncBenchmarkRefusal(string $environment, string $database, string $queue, string $longRunning): string
{
    return "Refusing to run the segment sync benchmark: it runs only with --env=benchmark on the srw-crm-bench database with sync queues (found {$environment}, pgsql/{$database}, queue {$queue}/{$longRunning}). Run php artisan config:clear and check .env.benchmark.";
}

/**
 * @return array<int, string|null> every segment's last_synced_at of an organization, by id
 */
function segmentSyncTimes(Organization $organization): array
{
    return Segment::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->orderBy('id')
        ->get()->mapWithKeys(fn (Segment $segment): array => [$segment->id => $segment->last_synced_at?->toIso8601String()])->all();
}

const BENCH_PUBLISHED_SEGMENTS = ['VIP', 'Laravel developers', 'Open deals', 'Won customers', 'Agency contacts', 'Never contacted', 'Gone quiet (hourly)', 'Added recently (daily)'];

test('every published segment is synced, timed and rebuilt', function () {
    $other = Organization::factory()->create();
    $organization = app(VolumeBenchmarkSeeder::class)->seedVolume(50);
    $otherSegment = Segment::factory()->published()->create(['organization_id' => $other->id, 'last_synced_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC')]);
    $agency = Segment::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->where('name', 'Agency contacts')->firstOrFail();
    DB::table('contact_segment')->where('segment_id', $agency->id)->delete();

    [$code, $output] = runSegmentSyncBenchmark();

    [$contacts, $companies, $deals, $activities] = app(TenantContext::class)->run($organization->id, fn (): array => [
        Contact::query()->count(), Company::query()->count(), Deal::query()->count(), Activity::query()->count(),
    ]);

    expect($code)->toBe(0)
        ->and($output)->toContain("Benchmark Org (#{$organization->id}): {$contacts} contacts, {$companies} companies, {$deals} deals, {$activities} activities.")
        ->and($output)->toContain('Synced 8 segments in ')
        ->and($output)->not->toContain('High rate (draft)')
        ->and($output)->toContain('Table')->toContain('Live rows')->toContain('Changed since analyze')->toContain('Last analyzed (UTC)')->toContain('contact_activities')
        ->and($output)->not->toContain('ANALYZE done in')->not->toContain('EXPLAIN ANALYZE');

    app(TenantContext::class)->run($organization->id, function () use ($output): void {
        foreach (BENCH_PUBLISHED_SEGMENTS as $name) {
            $segment = Segment::query()->where('name', $name)->firstOrFail();
            $members = $segment->queryBuilder()->count($segment->publishedRules());

            expect($output)->toMatch('/\|\s*'.preg_quote($name, '/').'\s*\|\s*'.$members.'\s*\|/')
                ->and($segment->contacts()->count())->toBe($members);
        }
    });

    expect(DB::table('contact_segment')->where('segment_id', $agency->id)->count())->toBeGreaterThan(0)
        ->and($otherSegment->fresh()->last_synced_at->toIso8601String())->toBe(Carbon::parse('2026-01-01 00:00:00', 'UTC')->toIso8601String());
});

test('analyze and explain add their sections', function () {
    app(VolumeBenchmarkSeeder::class)->seedVolume(50);

    [$code, $output] = runSegmentSyncBenchmark('--analyze --explain');

    expect($code)->toBe(0)
        ->and(strpos($output, 'ANALYZE done in '))->toBeInt()->toBeLessThan(strpos($output, 'Last analyzed (UTC)'))
        ->and($output)->toContain('EXPLAIN ANALYZE Agency contacts:')->toContain('EXPLAIN ANALYZE Gone quiet (hourly):')
        ->toContain('actual time=')->toContain('Execution Time:');
});

test('the benchmark pair is let through', function () {
    app(VolumeBenchmarkSeeder::class)->seedVolume(20);
    app()->instance('env', 'benchmark');
    config(['database.connections.pgsql.database' => 'srw-crm-bench']);

    [$code, $output] = runSegmentSyncBenchmark();

    expect($code)->toBe(0)->and($output)->toContain('Synced 8 segments in ');
});

test('without a benchmark organization it stops with a plain line', function () {
    [$code, $output] = runSegmentSyncBenchmark();

    expect($code)->toBe(1)
        ->and($output)->toContain('No benchmark organization found (owner bench-owner@bench.invalid). Seed it first with php artisan --env=benchmark db:seed --class=VolumeBenchmarkSeeder --no-interaction.')
        ->and($output)->not->toContain('Synced');
});

test('a run against anything but the benchmark or test database is refused and writes nothing', function (string $environment, string $database, string $queue, string $longRunning) {
    $organization = app(VolumeBenchmarkSeeder::class)->seedVolume(20);
    $times = segmentSyncTimes($organization);
    $links = DB::table('contact_segment')->count();
    app()->instance('env', $environment);
    config([
        'database.connections.pgsql.database' => $database,
        'queue.default' => $queue,
        'queue.long_running_connection' => $longRunning,
    ]);

    [$code, $output] = runSegmentSyncBenchmark('--analyze --explain');

    expect($code)->toBe(1)
        ->and($output)->toContain(segmentSyncBenchmarkRefusal($environment, $database, $queue, $longRunning))
        ->and($output)->not->toContain('ANALYZE done in')->not->toContain('Synced')->not->toContain('EXPLAIN ANALYZE')
        ->and(segmentSyncTimes($organization))->toBe($times)
        ->and(DB::table('contact_segment')->count())->toBe($links);
})->with([
    'local on dev' => ['local', 'srw-crm', 'sync', 'sync'],
    'benchmark env on dev database' => ['benchmark', 'srw-crm', 'sync', 'sync'],
    'testing env on dev database' => ['testing', 'srw-crm', 'sync', 'sync'],
    'testing env on the bench database' => ['testing', 'srw-crm-bench', 'sync', 'sync'],
    'benchmark env on a test database' => ['benchmark', 'srw-crm-test', 'sync', 'sync'],
    'redis queue' => ['benchmark', 'srw-crm-bench', 'redis', 'sync'],
    'redis long-running queue' => ['benchmark', 'srw-crm-bench', 'sync', 'redis-long'],
    'benchmark env on a bench prefix lookalike' => ['benchmark', 'srw-crm-bench_1', 'sync', 'sync'],
    'benchmark env on a bench dash lookalike' => ['benchmark', 'srw-crm-bench-old', 'sync', 'sync'],
    'benchmark env on a bench suffix lookalike' => ['benchmark', 'xsrw-crm-bench', 'sync', 'sync'],
    'benchmark env on an upper-case bench name' => ['benchmark', 'SRW-CRM-BENCH', 'sync', 'sync'],
]);

test('a cached configuration is refused and writes nothing', function () {
    $organization = app(VolumeBenchmarkSeeder::class)->seedVolume(20);
    $times = segmentSyncTimes($organization);
    app()->instance('env', 'benchmark');
    app()->instance('config_loaded_from_cache', true);
    config(['database.connections.pgsql.database' => 'srw-crm-bench']);

    [$code, $output] = runSegmentSyncBenchmark();

    expect($code)->toBe(1)
        ->and($output)->toContain('Refusing to run the segment sync benchmark')->not->toContain('Synced')
        ->and(segmentSyncTimes($organization))->toBe($times);
});

test('a connection that is not pgsql is refused and writes nothing', function () {
    $organization = app(VolumeBenchmarkSeeder::class)->seedVolume(20);
    $times = segmentSyncTimes($organization);
    app()->instance('env', 'benchmark');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => 'srw-crm-bench']);

    try {
        [$code, $output] = runSegmentSyncBenchmark('--analyze --explain');
    } finally {
        config(['database.default' => 'pgsql']);
    }

    expect($code)->toBe(1)
        ->and($output)->toContain('Refusing to run the segment sync benchmark')->toContain('sqlite/srw-crm-bench')->not->toContain('Synced')
        ->and(segmentSyncTimes($organization))->toBe($times);
});
