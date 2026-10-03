<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly: each command only handles the organizations where it is the configured hour in their own timezone.
Schedule::command('tasks:send-reminders')->hourly();
Schedule::command('tasks:send-digest')
    ->hourly()
    ->when(fn (): bool => (bool) config('tasks.notifications.daily_digest_enabled', true));

Schedule::command('segments:sync --frequency=hourly')->hourly()->withoutOverlapping();
// Every hour, for the organizations whose day just started in their timezone.
Schedule::command('segments:sync --frequency=daily --local-hour=0')->hourlyAt(5)->withoutOverlapping();

// Safety net: re-syncs every published segment weekly, so a write that skipped model events can't leave segments wrong forever.
Schedule::command('segments:sync')->weeklyOn(0, '03:30')->withoutOverlapping();

Schedule::command('custom-fields:report-duplicates')->dailyAt('02:00')->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
