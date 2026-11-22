<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bots:dispatch')->everySecond()->withoutOverlapping(2);

Schedule::command('bots:health')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('bots:sync-orders')->everyTenSeconds()->withoutOverlapping(2);
Schedule::command('bots:sync-balances')->everyMinute()->withoutOverlapping();
Schedule::command('analytics:capture-equity')
    ->cron('*/'.max(1, (int) config('analytics.capture_interval_minutes', 5)).' * * * *')
    ->withoutOverlapping();
Schedule::command('backtests:fail-stale')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('alerts:prune')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('analytics:prune-equity')->dailyAt('03:15')->withoutOverlapping();
