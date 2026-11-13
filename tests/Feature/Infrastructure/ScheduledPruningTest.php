<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('schedules each nightly prune command', function (string $command, string $expression) {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, $command));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe($expression);
})->with([
    ['uploads:prune', '10 3 * * *'],
    ['queue:prune-failed --hours=168', '15 3 * * *'],
    ['queue:prune-batches --hours=48 --unfinished=168 --cancelled=168', '15 3 * * *'],
    ['auth:clear-resets', '15 3 * * *'],
]);

it('does not let uploads:prune overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'uploads:prune'));

    expect($event->withoutOverlapping)->toBeTrue();
});

it('runs the framework prune commands as scheduled without error', function (string $command) {
    $this->artisan($command)->assertSuccessful();
})->with([
    'queue:prune-failed --hours=168',
    'queue:prune-batches --hours=48 --unfinished=168 --cancelled=168',
    'auth:clear-resets',
]);
