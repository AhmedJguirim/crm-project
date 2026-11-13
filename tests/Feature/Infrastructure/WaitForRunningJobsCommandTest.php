<?php

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * @param  array<string, int|array<int, int>>  $reserved  queue name => reserved size, or the sequence of sizes returned call after call
 */
function fakeReservedQueues(array $reserved): void
{
    $connections = [
        'redis' => ['default'],
        'redis-long' => ['segments', 'imports'],
    ];

    $mocks = [];

    foreach ($connections as $name => $queueNames) {
        $queue = Mockery::mock(RedisQueue::class);

        foreach ($queueNames as $queueName) {
            $queue->shouldReceive('reservedSize')
                ->with($queueName)
                ->andReturn(...(array) ($reserved[$queueName] ?? 0));
        }

        $mocks[$name] = $queue;
    }

    Queue::shouldReceive('connection')->andReturnUsing(fn (string $name): RedisQueue => $mocks[$name]);
}

/**
 * @param  array<int, array<int, array{name: string, status: string}>>  $snapshots  what `all()` returns, call after call; the last one repeats
 */
function fakeSupervisors(array $snapshots): void
{
    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->shouldReceive('all')->andReturn(...array_map(
        fn (array $rows): array => array_map(fn (array $row): object => (object) $row, $rows),
        $snapshots,
    ));

    app()->instance(SupervisorRepository::class, $repository);
}

beforeEach(fn () => fakeSupervisors([[]]));

test('nothing running: it says so and never sleeps', function () {
    Sleep::fake();
    fakeReservedQueues([]);

    $this->artisan('deploy:wait-for-jobs')
        ->expectsOutputToContain('No jobs are running.')
        ->assertExitCode(0);

    Sleep::assertNeverSlept();
});

test('an import finishes after two checks', function () {
    Sleep::fake();
    fakeReservedQueues(['imports' => [1, 1, 0]]);

    $code = Artisan::call('deploy:wait-for-jobs', ['--interval' => 5]);

    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and(substr_count($output, 'Waiting for running jobs: imports (1)'))->toBe(2)
        ->and($output)->toContain('No jobs are running.');

    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);
});

test('two busy queues are listed in config order', function () {
    Sleep::fake();
    fakeReservedQueues(['segments' => [2, 0], 'imports' => [1, 0]]);

    $this->artisan('deploy:wait-for-jobs')
        ->expectsOutputToContain('Waiting for running jobs: segments (2), imports (1)')
        ->assertExitCode(0);

    Sleep::assertSleptTimes(1);
});

test('the default queue is watched too', function () {
    Sleep::fake();
    fakeReservedQueues(['default' => [3, 0]]);

    $this->artisan('deploy:wait-for-jobs')
        ->expectsOutputToContain('Waiting for running jobs: default (3)')
        ->assertExitCode(0);
});

test('a job that never ends fails after the timeout', function () {
    Sleep::fake();
    fakeReservedQueues(['imports' => 1]);

    $this->artisan('deploy:wait-for-jobs --timeout=10 --interval=5')
        ->expectsOutputToContain('Jobs are still running after 10 seconds: imports (1).')
        ->assertExitCode(1);

    Sleep::assertSleptTimes(2);
});

test('a job that ends on the very last check still passes', function () {
    Sleep::fake();
    fakeReservedQueues(['imports' => [1, 1, 0]]);

    $this->artisan('deploy:wait-for-jobs --timeout=10 --interval=5')
        ->expectsOutputToContain('No jobs are running.')
        ->assertExitCode(0);

    Sleep::assertSleptTimes(2);
});

test('a zero timeout checks once and never sleeps', function () {
    Sleep::fake();
    fakeReservedQueues(['imports' => 1]);

    $this->artisan('deploy:wait-for-jobs --timeout=0')
        ->expectsOutputToContain('Jobs are still running after 0 seconds: imports (1).')
        ->assertExitCode(1);

    Sleep::assertNeverSlept();
});

test('a zero, negative or junk interval is raised to one second instead of dividing by zero', function (string $interval) {
    Sleep::fake();
    fakeReservedQueues(['imports' => 1]);

    $this->artisan('deploy:wait-for-jobs --timeout=3 --interval='.$interval)
        ->expectsOutputToContain('Jobs are still running after 3 seconds: imports (1).')
        ->assertExitCode(1);

    Sleep::assertSleptTimes(3);
    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(1)->seconds(), Sleep::for(1)->seconds()]);
})->with(['0', '-5', 'abc']);

test('with no supervisor configured it passes at once', function () {
    Sleep::fake();
    config(['horizon.defaults' => []]);

    $this->artisan('deploy:wait-for-jobs')
        ->expectsOutputToContain('No jobs are running.')
        ->assertExitCode(0);
});

test('it waits until every supervisor reports paused before counting jobs', function () {
    Sleep::fake();
    $order = [];
    $snapshots = [
        [(object) ['name' => 'host:1-supervisor-default', 'status' => 'running'], (object) ['name' => 'host:1-supervisor-imports', 'status' => 'paused']],
        [(object) ['name' => 'host:1-supervisor-default', 'status' => 'paused'], (object) ['name' => 'host:1-supervisor-imports', 'status' => 'paused']],
    ];
    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->shouldReceive('all')->andReturnUsing(function () use (&$order, &$snapshots): array {
        $order[] = 'all';

        return count($snapshots) > 1 ? array_shift($snapshots) : $snapshots[0];
    });
    app()->instance(SupervisorRepository::class, $repository);
    $queue = Mockery::mock(RedisQueue::class);
    $queue->shouldReceive('reservedSize')->andReturnUsing(function () use (&$order): int {
        $order[] = 'count';

        return 0;
    });
    Queue::shouldReceive('connection')->andReturn($queue);

    $code = Artisan::call('deploy:wait-for-jobs', ['--interval' => 5]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and(substr_count($output, 'Waiting for Horizon to pause: host:1-supervisor-default'))->toBe(1)
        ->and($output)->not->toContain('supervisor-imports')
        ->and($output)->toContain('No jobs are running.')
        ->and($order)->toBe(['all', 'all', 'count', 'count', 'count']);

    Sleep::assertSleptTimes(1);
});

test('a supervisor that never pauses fails after the timeout without counting jobs', function () {
    Sleep::fake();
    fakeSupervisors([[['name' => 'host:1-supervisor-imports', 'status' => 'running']]]);
    $queue = Mockery::mock(RedisQueue::class);
    $queue->shouldReceive('reservedSize')->never();
    Queue::shouldReceive('connection')->andReturn($queue);

    $code = Artisan::call('deploy:wait-for-jobs', ['--timeout' => 10, '--interval' => 5]);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and(substr_count($output, 'Waiting for Horizon to pause: host:1-supervisor-imports'))->toBe(2)
        ->and($output)->toContain('Horizon did not pause after 10 seconds: host:1-supervisor-imports.')
        ->and($output)->not->toContain('No jobs are running.');

    Sleep::assertSleptTimes(2);
});

test('a supervisor without a status counts as not paused', function () {
    Sleep::fake();
    fakeSupervisors([[['name' => 'host:1-supervisor-imports']]]);
    $queue = Mockery::mock(RedisQueue::class);
    $queue->shouldReceive('reservedSize')->never();
    Queue::shouldReceive('connection')->andReturn($queue);

    $code = Artisan::call('deploy:wait-for-jobs', ['--timeout' => 0]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('Horizon did not pause after 0 seconds: host:1-supervisor-imports.');
});

test('the pause wait and the job wait share one budget', function () {
    Sleep::fake();
    fakeSupervisors([
        [['name' => 'host:1-supervisor-imports', 'status' => 'running']],
        [['name' => 'host:1-supervisor-imports', 'status' => 'paused']],
    ]);
    fakeReservedQueues(['imports' => 1]);

    $code = Artisan::call('deploy:wait-for-jobs', ['--timeout' => 10, '--interval' => 5]);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('Jobs are still running after 10 seconds: imports (1).');

    Sleep::assertSleptTimes(2);
});

test('with no supervisor listed there is no pause wait', function () {
    Sleep::fake();
    fakeSupervisors([[]]);
    fakeReservedQueues([]);

    $this->artisan('deploy:wait-for-jobs')
        ->doesntExpectOutputToContain('Waiting for Horizon to pause')
        ->expectsOutputToContain('No jobs are running.')
        ->assertExitCode(0);

    Sleep::assertNeverSlept();
});
