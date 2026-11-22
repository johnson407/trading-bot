<?php

return [
    'timeframes' => ['1m', '5m', '15m', '1h', '4h', '1d'],

    'default_timeframe' => '15m',

    'default_days' => 30,

    'max_candles' => 200_000,

    'candle_chunk_limit' => 1_000,

    'fetch_window' => 5_000,

    'progress_interval' => 500,

    'equity_points' => 1_000,

    // A circuit breaker for pathological fill-heavy runs. The engine and job
    // keep fills in memory, so an unbounded count can exhaust a worker even
    // when the candle range itself is within the cap.
    'max_fills' => 25_000,

    // A sweep runs the cartesian product of its value lists in one request.
    // Keep the fan-out bounded; the effective cap is never below two so a
    // sweep always produces a comparable set.
    'sweep_max_runs' => 24,

    'job_timeout' => 600,
];
