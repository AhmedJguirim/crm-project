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
