<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('analytics:capture-equity')
    ->cron('*/'.max(1, (int) config('analytics.capture_interval_minutes', 5)).' * * * *')
    ->withoutOverlapping();

Schedule::command('alerts:prune')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('analytics:prune-equity')->dailyAt('03:15')->withoutOverlapping();

Schedule::command('alerts:ingest-engine-events')
    ->everyTenSeconds()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('alerts:check-engine-heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('scheduler:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
