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
