<?php

use App\Models\BacktestSweep;

it('caps sweeps at 24 runs by default and never below two', function () {
    expect(config('backtest.sweep_max_runs'))->toBe(24)
        ->and(BacktestSweep::maximumRuns())->toBe(24);

    config(['backtest.sweep_max_runs' => 1]);

    expect(BacktestSweep::maximumRuns())->toBe(2);

    config(['backtest.sweep_max_runs' => 0]);

    expect(BacktestSweep::maximumRuns())->toBe(2);
});
