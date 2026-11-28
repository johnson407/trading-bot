<?php

return [
    'runtime' => [
        'risk.daily_loss_limit' => ['severity' => 'critical', 'emitter' => 'engine/internal/runtime/tick.go'],
        'exchange.failure_threshold' => ['severity' => 'critical', 'emitter' => 'engine/internal/runtime/tick.go'],
        'risk.live_trading_disabled' => ['severity' => 'critical', 'emitter' => 'engine/internal/runtime/runtime.go'],
        'risk.stale_bot' => ['severity' => 'warning', 'emitter' => 'engine/internal/runtime/runtime.go'],
        'risk.live_cancel_failed' => ['severity' => 'critical', 'emitter' => 'engine/internal/runtime/tick.go'],
        'risk.cancel_failed' => ['severity' => 'warning', 'emitter' => 'engine/internal/runtime/tick.go'],
        'risk.live_start_refused' => ['severity' => 'critical', 'emitter' => 'engine/internal/runtime/runtime.go'],
        'risk.live_order_rejected' => ['severity' => 'critical', 'emitter' => 'engine/internal/runtime/live.go'],
        'risk.stop_loss_triggered' => ['severity' => 'critical', 'emitter' => 'engine/internal/runtime/tick.go'],
        'risk.take_profit_triggered' => ['severity' => 'warning', 'emitter' => 'engine/internal/runtime/tick.go'],
        'risk.range_exit_triggered' => ['severity' => 'warning', 'emitter' => 'engine/internal/runtime/tick.go'],
        'engine.heartbeat_missing' => ['severity' => 'critical', 'emitter' => 'app/Console/Commands/CheckEngineHeartbeat.php'],
    ],

    'non_alertable' => [
        'risk.kill_switch_activated',
    ],

    'laravel' => [
        'kill_switch.activated',
    ],

    'legacy' => [
        'risk.reconciliation_conflict',
        'risk.strategy_stop_cancel_failed',
        'risk.daily_loss_cancel_failed',
        'risk.kill_switch_cancel_failed',
        'risk.failure_cancel_failed',
        'risk.exit_cancel_failed',
    ],
];
