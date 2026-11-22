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
    ];

    expect($incidents)->toHaveKeys(array_keys($expected));

    foreach ($expected as $type => $severity) {
        expect($incidents[$type]['severity'])->toBe($severity)
            ->and($incidents[$type]['title'])->toBeString()->not->toBe('');
    }
});
