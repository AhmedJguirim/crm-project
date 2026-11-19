<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\VolumeBenchmarkSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the volume benchmark with a small count, the way the testing environment allows it.
 */
function seedBenchmark(int $contacts = 50): Organization
{
    return app(VolumeBenchmarkSeeder::class)->seedVolume($contacts);
}

/**
 * Runs a callback inside the tenant context of an organization.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inBenchOrganization(Organization $organization, Closure $callback): mixed
{
    return app(TenantContext::class)->run($organization->id, $callback);
}

/**
 * The exact refusal message of the seeder.
 */
function benchmarkRefusal(string $environment, string $database, string $queue, string $connection = 'pgsql'): string
{
    return "Refusing to seed the volume benchmark: it runs only with --env=benchmark on the srw-crm-bench database with sync queues (found {$environment}, {$connection}/{$database}, queue {$queue}). Run php artisan config:clear and check .env.benchmark.";
}

test('a small run builds the scaled volume in one organization', function () {
    $otherOrganization = Organization::factory()->create();
    Contact::factory()->count(2)->create(['organization_id' => $otherOrganization->id]);
    $otherContacts = Contact::query()->withoutGlobalScopes()->where('organization_id', $otherOrganization->id)->count();

    $organization = seedBenchmark(50);

    expect($organization->name)->toBe('Benchmark Org')
        ->and($organization->owner->email)->toBe('bench-owner@bench.invalid');

    $roles = $organization->members()->pluck('organization_user.role', 'email');
    expect($roles['bench-admin@bench.invalid'])->toBe(OrganizationRole::Admin->value)
        ->and($roles['bench-member@bench.invalid'])->toBe(OrganizationRole::Member->value);

    inBenchOrganization($organization, function () use ($organization): void {
        $benchContactIds = Contact::query()->where('email', 'like', 'bench.contact.%')->pluck('id');
        $benchDeals = Deal::query()->where('title', 'like', 'Bench Deal %')->get();

        expect($benchContactIds)->toHaveCount(50)
            ->and(Company::query()->where('name', 'like', 'Bench Company %')->count())->toBe(10)
            ->and(DB::table('company_contact')->whereIn('contact_id', $benchContactIds)->count())->toBe(40)
            ->and(DB::table('contact_tag')->whereIn('contact_id', $benchContactIds)->count())->toBe(67)
            ->and(Activity::query()->where('subject', 'like', 'Bench activity %')->count())->toBe(100)
            ->and($benchDeals)->toHaveCount(10)
            ->and(Task::query()->where('title', 'like', 'Bench Task %')->count())->toBe(10)
            ->and(Invoice::query()->where('invoice_number', 'like', 'BENCH-%')->count())->toBe(5)
            ->and($benchDeals->where('status', DealStatus::Open))->toHaveCount(7)
            ->and($benchDeals->where('status', DealStatus::Won))->toHaveCount(2)
            ->and($benchDeals->where('status', DealStatus::Won)->whereNotNull('won_at'))->toHaveCount(2)
            ->and($benchDeals->where('status', DealStatus::Lost))->toHaveCount(1)
            ->and($benchDeals->where('status', DealStatus::Lost)->whereNotNull('lost_at'))->toHaveCount(1)
            ->and(DealStageChange::query()->whereIn('deal_id', $benchDeals->modelKeys())->distinct()->count('deal_id'))->toBe(10)
            ->and(Contact::query()->where('organization_id', '!=', $organization->id)->count())->toBe(0);
    });

    expect(Contact::query()->withoutGlobalScopes()->where('organization_id', $otherOrganization->id)->count())->toBe($otherContacts)
        ->and(Contact::query()->withoutGlobalScopes()->where('email', 'like', 'bench.contact.%')->where('organization_id', '!=', $organization->id)->count())->toBe(0);
});

test('every bench user has a random password and an address no mail can reach', function () {
    seedBenchmark(20);

    $users = User::query()->whereIn('email', ['bench-owner@bench.invalid', 'bench-admin@bench.invalid', 'bench-member@bench.invalid'])->get();

    expect($users)->toHaveCount(3)
        ->and($users->every(fn (User $user): bool => ! Hash::check('password', $user->password) && $user->email_verified_at !== null))->toBeTrue()
        ->and($users->pluck('password')->unique())->toHaveCount(3);
});

test('segment memberships are correct afterwards', function () {
    $organization = seedBenchmark(50);

    inBenchOrganization($organization, function (): void {
        $segments = Segment::query()->where('is_published', true)->get();
        expect($segments)->not->toBeEmpty();

        foreach ($segments as $segment) {
            $expected = $segment->queryBuilder()->matching($segment->publishedRules())->pluck('contacts.id')->sort()->values()->all();
            $actual = DB::table('contact_segment')->where('segment_id', $segment->id)->pluck('contact_id')->sort()->values()->all();

            expect($actual)->toBe($expected, "segment {$segment->name}");
        }

        $benchContactIds = Contact::query()->where('email', 'like', 'bench.contact.%')->pluck('id');
        $neverContacted = Segment::query()->where('name', 'Never contacted')->sole();

        expect(DB::table('contact_segment')->where('segment_id', $neverContacted->id)->whereIn('contact_id', $benchContactIds)->count())->toBe(0)
            ->and(DB::table('contact_segment')->whereIn('segment_id', $segments->modelKeys())->whereIn('contact_id', $benchContactIds)->count())->toBeGreaterThan(0);
    });
});

test('a second run is refused and leaves the first untouched', function () {
    $organization = seedBenchmark(20);

    expect(fn () => seedBenchmark(20))
        ->toThrow(RuntimeException::class, 'The benchmark organization already exists. Run php artisan --env=benchmark migrate:fresh --no-interaction first.');

    expect(User::query()->where('email', 'bench-owner@bench.invalid')->count())->toBe(1)
        ->and(Organization::query()->where('name', 'Benchmark Org')->count())->toBe(1)
        ->and(inBenchOrganization($organization, fn (): int => Contact::query()->where('email', 'like', 'bench.contact.%')->count()))->toBe(20);
});

test('a failure in the middle rolls the whole run back', function () {
    $organizations = Organization::query()->count();
    $users = User::query()->count();

    Task::creating(fn () => throw new RuntimeException('boom'));

    expect(fn () => seedBenchmark(20))->toThrow(RuntimeException::class, 'boom');

    expect(User::query()->where('email', 'bench-owner@bench.invalid')->exists())->toBeFalse()
        ->and(Organization::query()->count())->toBe($organizations)
        ->and(User::query()->count())->toBe($users)
        ->and(Contact::query()->withoutGlobalScopes()->where('email', 'like', 'bench.contact.%')->count())->toBe(0);
});

test('the benchmark pair is let through', function () {
    app()->instance('env', 'benchmark');
    config(['database.connections.pgsql.database' => 'srw-crm-bench']);

    $organization = seedBenchmark(20);

    expect($organization->name)->toBe('Benchmark Org')
        ->and(inBenchOrganization($organization, fn (): int => Contact::query()->where('email', 'like', 'bench.contact.%')->count()))->toBe(20);
});

test('the seeder refuses anything but the benchmark or test database and writes nothing', function (string $environment, string $database, string $queue, string $longRunning) {
    app()->instance('env', $environment);
    config([
        'database.connections.pgsql.database' => $database,
        'queue.default' => $queue,
        'queue.long_running_connection' => $longRunning,
    ]);
    $organizations = Organization::query()->count();
    $users = User::query()->count();

    expect(fn () => seedBenchmark(20))
        ->toThrow(RuntimeException::class, benchmarkRefusal($environment, $database, "{$queue}/{$longRunning}"));

    expect(User::query()->where('email', 'bench-owner@bench.invalid')->exists())->toBeFalse()
        ->and(Organization::query()->count())->toBe($organizations)
        ->and(User::query()->count())->toBe($users);
})->with([
    'local on dev' => ['local', 'srw-crm', 'sync', 'sync'],
    'benchmark env on dev database' => ['benchmark', 'srw-crm', 'sync', 'sync'],
    'local env on the bench database' => ['local', 'srw-crm-bench', 'sync', 'sync'],
    'production on the bench database' => ['production', 'srw-crm-bench', 'sync', 'sync'],
    'testing env on dev database' => ['testing', 'srw-crm', 'sync', 'sync'],
    'testing env on the bench database' => ['testing', 'srw-crm-bench', 'sync', 'sync'],
    'benchmark env on a test database' => ['benchmark', 'srw-crm-test', 'sync', 'sync'],
    'testing env on a lookalike database' => ['testing', "srw-crm-test\nsrw-crm", 'sync', 'sync'],
    'redis queue' => ['benchmark', 'srw-crm-bench', 'redis', 'sync'],
    'redis long-running queue' => ['benchmark', 'srw-crm-bench', 'sync', 'redis-long'],
    'benchmark env on a bench prefix lookalike' => ['benchmark', 'srw-crm-bench_1', 'sync', 'sync'],
    'benchmark env on a bench dash lookalike' => ['benchmark', 'srw-crm-bench-old', 'sync', 'sync'],
    'benchmark env on a bench suffix lookalike' => ['benchmark', 'xsrw-crm-bench', 'sync', 'sync'],
    'benchmark env on an upper-case bench name' => ['benchmark', 'SRW-CRM-BENCH', 'sync', 'sync'],
    'benchmark env on the bench name with a trailing newline' => ['benchmark', "srw-crm-bench\n", 'sync', 'sync'],
]);

test('the seeder refuses a connection that is not pgsql', function () {
    $organizations = Organization::query()->count();
    app()->instance('env', 'benchmark');
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => 'srw-crm-bench',
    ]);

    $refused = fn () => app(VolumeBenchmarkSeeder::class)->seedVolume(20);

    try {
        expect($refused)->toThrow(RuntimeException::class, benchmarkRefusal('benchmark', 'srw-crm-bench', 'sync/sync', 'sqlite'));
    } finally {
        config(['database.default' => 'pgsql']);
    }

    expect(User::query()->where('email', 'bench-owner@bench.invalid')->exists())->toBeFalse()
        ->and(Organization::query()->count())->toBe($organizations);
});

test('the seeder refuses a cached configuration', function () {
    $organizations = Organization::query()->count();
    app()->instance('env', 'benchmark');
    app()->instance('config_loaded_from_cache', true);
    config(['database.connections.pgsql.database' => 'srw-crm-bench']);

    expect(app()->configurationIsCached())->toBeTrue()
        ->and(fn () => seedBenchmark(20))->toThrow(RuntimeException::class, 'Refusing to seed the volume benchmark')
        ->and(User::query()->where('email', 'bench-owner@bench.invalid')->exists())->toBeFalse()
        ->and(Organization::query()->count())->toBe($organizations);
});

test('the seeder is not called by DatabaseSeeder and the config file stays git-ignored', function () {
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('VolumeBenchmarkSeeder')
        ->and(file($path = base_path('.gitignore'), FILE_IGNORE_NEW_LINES))->toContain('.env.benchmark');
});
