<?php

namespace Database\Factories;

use App\Models\BacktestFill;
use App\Models\BacktestRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class BacktestFillFactory extends Factory
{
    protected $model = BacktestFill::class;

    public function definition(): array
    {
        return [
            'backtest_run_id' => BacktestRun::factory(),
            'sequence' => fake()->unique()->numberBetween(1, 10_000),
            'side' => 'buy',
            'price' => '100',
            'quantity' => '1',
            'fee' => '0.1',
            'fee_currency' => 'USDT',
            'filled_at' => now('UTC'),
            'grid_level_sequence' => 1,
        ];
    }
}
