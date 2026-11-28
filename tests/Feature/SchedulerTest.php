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

    expect($commands)->toHaveCount(5)
        ->and($commands->contains(fn (string $command) => str_contains($command, 'analytics:capture-equity')))->toBeTrue()
        ->and($commands->contains(fn (string $command) => str_contains($command, 'alerts:prune')))->toBeTrue()
        ->and($commands->contains(fn (string $command) => str_contains($command, 'analytics:prune-equity')))->toBeTrue()
        ->and($commands->contains(fn (string $command) => str_contains($command, 'alerts:ingest-engine-events')))->toBeTrue()
        ->and($commands->contains(fn (string $command) => str_contains($command, 'alerts:check-engine-heartbeat')))->toBeTrue();
});

it('runs the engine alert bridge every ten seconds and the heartbeat check every minute', function () {
    $events = collect(app(Schedule::class)->events());

    $ingest = $events->first(fn ($event) => str_contains((string) $event->command, 'alerts:ingest-engine-events'));
    $heartbeat = $events->first(fn ($event) => str_contains((string) $event->command, 'alerts:check-engine-heartbeat'));

    expect($ingest)->not->toBeNull()
        ->and($ingest->expression)->toBe('* * * * *')
        ->and($ingest->repeatSeconds)->toBe(10)
        ->and($ingest->withoutOverlapping)->toBeTrue()
        ->and($ingest->onOneServer)->toBeTrue()
        ->and($heartbeat)->not->toBeNull()
        ->and($heartbeat->expression)->toBe('* * * * *')
        ->and($heartbeat->repeatSeconds)->toBeNull()
        ->and($heartbeat->withoutOverlapping)->toBeTrue()
        ->and($heartbeat->onOneServer)->toBeTrue();
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
