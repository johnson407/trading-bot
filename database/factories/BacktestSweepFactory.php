<?php

namespace Database\Factories;

use App\Models\BacktestSweep;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BacktestSweepFactory extends Factory
{
    protected $model = BacktestSweep::class;

    public function definition(): array
    {
        $start = now('UTC')->subDays(30)->startOfDay();
        $end = now('UTC')->startOfDay();

        return [
            'user_id' => User::factory(),
            'bot_id' => null,
            'name' => 'Grid sweep',
            'symbol' => 'BTC/USDT',
            'timeframe' => '15m',
            'start_at' => $start,
            'end_at' => $end,
            'parameters' => [
                'center_prices' => ['50000', '51000'],
                'spacing_values' => ['100'],
                'level_counts' => [6],
            ],
            'total_runs' => 2,
        ];
    }

    public function forBot(Bot $bot): static
    {
        return $this->state(fn () => [
            'user_id' => $bot->user_id,
            'bot_id' => $bot->id,
            'symbol' => $bot->symbol,
        ]);
    }
}
