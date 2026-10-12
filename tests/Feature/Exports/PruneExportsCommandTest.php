<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->exportModifiedDaysAgo = function (string $name, int $days): void {
        Storage::disk('local')->put("exports/{$name}", 'x');
        touch(Storage::disk('local')->path("exports/{$name}"), now()->subDays($days)->getTimestamp());
    };
});

it('deletes the export files more than 7 days old and keeps the others', function () {
    ($this->exportModifiedDaysAgo)('old.xlsx', 8);
    ($this->exportModifiedDaysAgo)('recent.csv', 1);

    $this->artisan('exports:prune')->expectsOutputToContain('Deleted 1 export file.')->assertSuccessful();

    expect(Storage::disk('local')->files('exports'))->toBe(['exports/recent.csv']);
});

it('keeps a file that is exactly 7 days old, as the link lasts 7 days', function () {
    ($this->exportModifiedDaysAgo)('edge.xlsx', 7);

    $this->artisan('exports:prune')->expectsOutputToContain('Deleted 0 export files.')->assertSuccessful();

    expect(Storage::disk('local')->exists('exports/edge.xlsx'))->toBeTrue();
});

it('pluralizes and leaves other folders alone', function () {
    ($this->exportModifiedDaysAgo)('a.xlsx', 9);
    ($this->exportModifiedDaysAgo)('b.csv', 30);
    Storage::disk('local')->put('contact-imports/failed-old.csv', 'x');
    touch(Storage::disk('local')->path('contact-imports/failed-old.csv'), now()->subDays(30)->getTimestamp());

    $this->artisan('exports:prune')->expectsOutputToContain('Deleted 2 export files.');

    expect(Storage::disk('local')->exists('contact-imports/failed-old.csv'))->toBeTrue();
});

it('works when there is no exports folder yet', function () {
    $this->artisan('exports:prune')->expectsOutputToContain('Deleted 0 export files.')->assertSuccessful();
});

it('is scheduled daily at 03:00 without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'exports:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
