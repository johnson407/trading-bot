<?php

it('boots the application with live trading disabled', function () {
    expect(app())->not->toBeNull()
        ->and(config('trading.live_enabled'))->toBeFalse()
        ->and(config('trading.default_exchange'))->toBe('binance');
});

it('serves the health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('exposes bounded grid activity defaults', function () {
    expect(config('trading.grid_activity'))->toMatchArray([
        'default_range' => '24h',
        'cache_seconds' => 60,
        'failure_cache_seconds' => 30,
        'request_timeout_ms' => 5000,
        'max_attempts' => 1,
        'max_markers' => 2000,
        'max_grids' => 200,
    ])->and(config('trading.grid_activity.ranges'))->toMatchArray([
        '1h' => ['timeframe' => '1m', 'limit' => 60, 'seconds' => 3600],
        '6h' => ['timeframe' => '5m', 'limit' => 72, 'seconds' => 21600],
        '24h' => ['timeframe' => '5m', 'limit' => 288, 'seconds' => 86400],
        '7d' => ['timeframe' => '1h', 'limit' => 168, 'seconds' => 604800],
    ]);
});
