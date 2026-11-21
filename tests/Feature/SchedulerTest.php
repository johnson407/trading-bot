<?php

use Illuminate\Console\Scheduling\Schedule;

it('dispatches bots every second with a short overlap lock', function () {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn ($event) => str_contains((string) $event->command, 'bots:dispatch'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->expiresAt)->toBe(2);
});
