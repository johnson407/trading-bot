<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Live trading gate
    |--------------------------------------------------------------------------
    |
    | Live orders are disabled unless this is explicitly enabled. Even when
    | enabled, a bot must be created with confirmation data and pass the
    | balance check before it can trade real funds.
    |
    */
    'live_enabled' => (bool) env('TRADING_LIVE_ENABLED', false),

    'default_exchange' => env('TRADING_DEFAULT_EXCHANGE', 'binance'),

    /*
    |--------------------------------------------------------------------------
    | Exchange allowlist
    |--------------------------------------------------------------------------
    |
    | Only these CCXT exchange ids may be selected when creating a bot. Add
    | more ids as a comma-separated list in TRADING_EXCHANGES, for example
    | "binance,kraken".
    |
    */
    'exchanges' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRADING_EXCHANGES', 'binance')),
    ))),

    'paper' => [
        // Paper fills happen at the limit price. Fees are a fraction of
        // notional, e.g. 0.001 for 0.1%.
        'fee_rate' => env('TRADING_PAPER_FEE_RATE', '0'),
    ],

    'tick' => [
        'interval_seconds' => (int) env('TRADING_TICK_INTERVAL', 1),
        'stale_after_seconds' => (int) env('TRADING_STALE_TICK_SECONDS', 60),
    ],

    'health' => [
        'check_interval_seconds' => (int) env('TRADING_HEALTH_CHECK_SECONDS', 30),
    ],

    'balances' => [
        'sync_interval_seconds' => (int) env('TRADING_BALANCE_SYNC_SECONDS', 60),
    ],

    'exchange' => [
        'timeout_ms' => (int) env('TRADING_EXCHANGE_TIMEOUT_MS', 15000),
        'rate_limit_ms' => (int) env('TRADING_EXCHANGE_RATE_LIMIT_MS', 1000),
        'max_retries' => (int) env('TRADING_EXCHANGE_MAX_RETRIES', 3),
        'retry_delay_ms' => (int) env('TRADING_EXCHANGE_RETRY_DELAY_MS', 250),
        'sandbox' => (bool) env('TRADING_EXCHANGE_SANDBOX', false),
    ],

    'orders' => [
        'sync_interval_seconds' => (int) env('TRADING_ORDER_SYNC_SECONDS', 5),
    ],

    'reconciliation' => [
        // Absolute quantity tolerance for comparing local and exchange balances.
        'tolerance' => env('TRADING_RECONCILIATION_TOLERANCE', '0.0001'),
    ],

    'failure' => [
        'max_consecutive' => (int) env('TRADING_MAX_CONSECUTIVE_FAILURES', 3),
    ],

    'capacity' => [
        'target_bots' => 10,
    ],
];
