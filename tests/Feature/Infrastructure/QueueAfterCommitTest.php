<?php

use App\Jobs\SyncSegmentMembership;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function jobsTableTransport(string $connection): void
{
    config(["queue.connections.{$connection}" => [
        ...config("queue.connections.{$connection}"),
        'driver' => 'database',
        'connection' => null,
        'table' => 'jobs',
    ]]);
}

function dispatchInRolledBackTransaction(Closure $dispatch): void
{
    try {
        DB::transaction(function () use ($dispatch) {
            $dispatch();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
}

describe('connection config', function () {
    it('defers to the commit on the production and test connections', function (string $connection) {
        expect(config("queue.connections.{$connection}.after_commit"))->toBeTrue();
    })->with(['redis', 'redis-long', 'sync']);
});

describe('long-running connection', function () {
    beforeEach(function () {
        config(['queue.long_running_connection' => 'redis-long']);
        jobsTableTransport('redis-long');
        DB::table('jobs')->delete();
    });

    it('never pushes a segment sync from a rolled-back transaction and does not block the next one', function () {
        dispatchInRolledBackTransaction(fn () => SyncSegmentMembership::dispatch(4242));

        expect(DB::table('jobs')->count())->toBe(0);

        SyncSegmentMembership::dispatch(4242);

        expect(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('jobs')->value('payload'))->toContain('SyncSegmentMembership');
    });

    it('pushes a segment sync only at the commit', function () {
        $insideTransaction = null;

        DB::transaction(function () use (&$insideTransaction) {
            SyncSegmentMembership::dispatch(4242);

            $insideTransaction = DB::table('jobs')->count();
        });

        expect($insideTransaction)->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('jobs')->value('payload'))->toContain('SyncSegmentMembership');
    });
});

describe('default connection', function () {
    beforeEach(function () {
        config(['queue.default' => 'redis']);
        jobsTableTransport('redis');
        DB::table('jobs')->delete();
        $this->user = User::factory()->create(['email' => 'reset@example.com']);
    });

    it('never pushes a queued mail from a rolled-back transaction', function () {
        dispatchInRolledBackTransaction(fn () => $this->user->notify(new QueuedResetPassword('plain-reset-token')));

        expect(DB::table('jobs')->count())->toBe(0);
    });

    it('pushes a queued mail only at the commit', function () {
        $insideTransaction = null;

        DB::transaction(function () use (&$insideTransaction) {
            $this->user->notify(new QueuedResetPassword('plain-reset-token'));

            $insideTransaction = DB::table('jobs')->count();
        });

        expect($insideTransaction)->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('jobs')->value('payload'))->toContain('QueuedResetPassword');
    });
});

describe('sync connection', function () {
    it('runs nothing for a rolled-back transaction and runs the job outside one', function () {
        expect(config('queue.default'))->toBe('sync');

        $processed = 0;
        Event::listen(JobProcessed::class, function () use (&$processed) {
            $processed++;
        });
        $user = User::factory()->create(['email' => 'reset@example.com']);

        dispatchInRolledBackTransaction(fn () => $user->notify(new QueuedResetPassword('plain-reset-token')));

        expect($processed)->toBe(0);

        $user->notify(new QueuedResetPassword('plain-reset-token'));

        expect($processed)->toBe(1);
    });
});
