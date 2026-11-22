<?php

return [
    'enabled' => (bool) env('ALERTS_ENABLED', true),

    // Duplicate incidents for the same owner, bot, and type are suppressed
    // inside this fixed window.
    'dedupe_window_seconds' => max(1, (int) env('ALERTS_DEDUPE_WINDOW_SECONDS', 900)),

    // Alerts older than this many days are removed by alerts:prune. Source
    // risk events and activity logs are preserved.
    'retention_days' => max(1, (int) env('ALERTS_RETENTION_DAYS', 90)),

    'incidents' => [
        'risk.daily_loss_limit' => [
            'severity' => 'critical',
            'title' => 'Daily loss limit reached',
        ],
        'exchange.failure_threshold' => [
            'severity' => 'critical',
            'title' => 'Exchange failures paused a bot',
        ],
        'risk.reconciliation_conflict' => [
            'severity' => 'critical',
            'title' => 'Reconciliation conflict',
        ],
        'risk.live_trading_disabled' => [
            'severity' => 'critical',
            'title' => 'Live trading disabled',
        ],
        'risk.stale_bot' => [
            'severity' => 'warning',
            'title' => 'Bot tick loop stale',
        ],
        'kill_switch.activated' => [
            'severity' => 'critical',
            'title' => 'Kill switch activated',
        ],
        'risk.daily_loss_cancel_failed' => [
            'severity' => 'critical',
            'title' => 'Orders could not be canceled after daily loss pause',
        ],
        'risk.kill_switch_cancel_failed' => [
            'severity' => 'critical',
            'title' => 'Orders could not be canceled after kill switch',
        ],
        'risk.strategy_stop_cancel_failed' => [
            'severity' => 'critical',
            'title' => 'Orders could not be canceled after strategy stop',
        ],
        'risk.live_cancel_failed' => [
            'severity' => 'critical',
            'title' => 'Orders could not be canceled after live gate pause',
        ],
        'risk.failure_cancel_failed' => [
            'severity' => 'critical',
            'title' => 'Orders could not be canceled after exchange failure pause',
        ],
        'risk.stop_loss_triggered' => [
            'severity' => 'critical',
            'title' => 'Stop-loss triggered',
        ],
        'risk.take_profit_triggered' => [
            'severity' => 'warning',
            'title' => 'Take-profit triggered',
        ],
        'risk.range_exit_triggered' => [
            'severity' => 'warning',
            'title' => 'Range exit triggered',
        ],
        'risk.exit_cancel_failed' => [
            'severity' => 'critical',
            'title' => 'Orders could not be canceled after protective exit',
        ],
    ],
];
