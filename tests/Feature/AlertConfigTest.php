<?php

it('exposes alert defaults and a complete incident catalog', function () {
    expect(config('alerts.enabled'))->toBeTrue()
        ->and(config('alerts.dedupe_window_seconds'))->toBe(900)
        ->and(config('alerts.retention_days'))->toBe(90);

    $incidents = config('alerts.incidents');

    expect($incidents)->toBeArray()->not->toBeEmpty();

    $expected = [
        'risk.daily_loss_limit' => 'critical',
        'exchange.failure_threshold' => 'critical',
        'risk.reconciliation_conflict' => 'critical',
        'risk.live_trading_disabled' => 'critical',
        'risk.stale_bot' => 'warning',
        'kill_switch.activated' => 'critical',
        'risk.daily_loss_cancel_failed' => 'critical',
        'risk.kill_switch_cancel_failed' => 'critical',
        'risk.strategy_stop_cancel_failed' => 'critical',
        'risk.live_cancel_failed' => 'critical',
        'risk.failure_cancel_failed' => 'critical',
        'risk.stop_loss_triggered' => 'critical',
        'risk.take_profit_triggered' => 'warning',
        'risk.range_exit_triggered' => 'warning',
        'risk.exit_cancel_failed' => 'critical',
        'risk.cancel_failed' => 'warning',
        'risk.stop_cancel_failed' => 'critical',
        'risk.live_start_refused' => 'critical',
        'risk.live_order_rejected' => 'critical',
        'engine.heartbeat_missing' => 'critical',
        'alerts.ingestion_failed' => 'critical',
    ];

    expect($incidents)->toHaveKeys(array_keys($expected));

    foreach ($expected as $type => $severity) {
        expect($incidents[$type]['severity'])->toBe($severity)
            ->and($incidents[$type]['title'])->toBeString()->not->toBe('');
    }
});

it('keeps runtime engine events and the alert catalog aligned', function () {
    $fixture = require base_path('tests/Fixtures/engine_alert_events.php');
    $incidents = config('alerts.incidents');

    foreach ($fixture['runtime'] as $type => $meta) {
        $emitter = base_path($meta['emitter']);

        expect($incidents)->toHaveKey($type)
            ->and($incidents[$type]['severity'])->toBe($meta['severity'])
            ->and($fixture['legacy'])->not->toContain($type)
            ->and(file_exists($emitter))->toBeTrue()
            ->and(file_get_contents($emitter))->toContain($type);
    }

    foreach ($fixture['non_alertable'] as $type => $emitter) {
        expect($incidents)->not->toHaveKey($type)
            ->and(file_get_contents(base_path($emitter)))->toContain($type);
    }

    foreach ($fixture['laravel'] as $type => $emitter) {
        expect($incidents)->toHaveKey($type)
            ->and(file_get_contents(base_path($emitter)))->toContain($type);
    }

    foreach (array_keys($incidents) as $type) {
        expect(array_merge(array_keys($fixture['runtime']), array_keys($fixture['laravel']), $fixture['legacy']))->toContain($type);
    }
});
