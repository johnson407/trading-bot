<?php

it('documents configuration keys that exist', function () {
    $docs = file_get_contents(base_path('docs/exchanges.md'));

    $keys = [
        'trading.default_exchange',
        'trading.live_enabled',
        'trading.paper.fee_rate',
        'trading.exchange.timeout_ms',
        'trading.exchange.rate_limit_ms',
        'trading.exchange.max_retries',
        'trading.exchange.retry_delay_ms',
        'trading.exchange.sandbox',
        'trading.failure.max_consecutive',
        'trading.reconciliation.tolerance',
        'trading.orders.sync_interval_seconds',
        'trading.grid_activity.default_range',
        'trading.grid_activity.cache_seconds',
        'trading.grid_activity.failure_cache_seconds',
        'trading.grid_activity.request_timeout_ms',
        'trading.grid_activity.max_attempts',
        'trading.grid_activity.max_markers',
        'trading.grid_activity.max_grids',
    ];

    foreach ($keys as $key) {
        expect($docs)->toContain($key)
            ->and(config()->has($key))->toBeTrue();
    }

    expect($docs)->toContain('| `trading.tick.interval_seconds` | `TRADING_TICK_INTERVAL` | `10` |')
        ->and($docs)->toContain('php artisan horizon')
        ->and($docs)->toContain('php artisan schedule:work')
        ->and($docs)->toContain('TRADING_LIVE_ENABLED')
        ->and($docs)->toContain('CCXT');
});
