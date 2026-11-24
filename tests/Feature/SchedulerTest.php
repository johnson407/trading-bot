<?php

use Illuminate\Console\Scheduling\Schedule;

it('only keeps alerts and analytics on the Laravel scheduler', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command);

    foreach ([
        'bots:dispatch',
        'bots:health',
        'bots:sync-orders',
        'bots:sync-balances',
        'bots:reconcile',
        'backtests:fail-stale',
    ] as $removed) {
        expect($commands->contains(fn (string $command) => str_contains($command, $removed)))->toBeFalse();
    }

    expect($commands)->toHaveCount(3)
        ->and($commands->contains(fn (string $command) => str_contains($command, 'analytics:capture-equity')))->toBeTrue()
        ->and($commands->contains(fn (string $command) => str_contains($command, 'alerts:prune')))->toBeTrue()
        ->and($commands->contains(fn (string $command) => str_contains($command, 'analytics:prune-equity')))->toBeTrue();
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

it('trims Horizon to the default queue only', function () {
    $horizon = config('horizon');

    expect($horizon['waits'])->toBe(['redis:default' => 60])
        ->and($horizon['defaults'])->toHaveKey('supervisor-1')
        ->and($horizon['defaults'])->not->toHaveKey('backtests')
        ->and($horizon['defaults']['supervisor-1']['queue'])->toBe(['default']);
});
