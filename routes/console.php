<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tasks:send-reminders')->dailyAt('07:00');
Schedule::command('tasks:send-digest')
    ->dailyAt(config('tasks.notifications.daily_digest_time', '08:00'))
    ->when(fn (): bool => (bool) config('tasks.notifications.daily_digest_enabled', true));

Schedule::command('segments:sync --frequency=hourly')->hourly()->withoutOverlapping();
Schedule::command('segments:sync --frequency=daily')->dailyAt('00:05')->withoutOverlapping();

// Safety net: re-syncs every published segment weekly, so a write that skipped model events can't leave segments wrong forever.
Schedule::command('segments:sync')->weeklyOn(0, '03:30')->withoutOverlapping();

Schedule::command('custom-fields:report-duplicates')->dailyAt('02:00')->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
