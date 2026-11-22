<?php

use Illuminate\Console\Scheduling\Schedule;

it('dispatches bots every second with a short overlap lock', function () {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn ($event) => str_contains((string) $event->command, 'bots:dispatch'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->expiresAt)->toBe(2);
});

it('schedules equity capture and pruning', function () {
    $events = collect(app(Schedule::class)->events());

    $capture = $events->first(fn ($event) => str_contains((string) $event->command, 'analytics:capture-equity'));
    $prune = $events->first(fn ($event) => str_contains((string) $event->command, 'analytics:prune-equity'));

    expect($capture)->not->toBeNull()
        ->and($capture->expression)->toBe('*/5 * * * *')
        ->and($capture->withoutOverlapping)->toBeTrue()
        ->and($prune)->not->toBeNull()
        ->and($prune->expression)->toBe('15 3 * * *')
        ->and($prune->withoutOverlapping)->toBeTrue();
});
