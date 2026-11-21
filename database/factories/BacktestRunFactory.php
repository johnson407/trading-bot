<?php

namespace Database\Factories;

use App\Domain\Backtest\Enums\BacktestEndReason;
use App\Domain\Backtest\Enums\BacktestStatus;
use App\Models\BacktestRun;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BacktestRunFactory extends Factory
{
    protected $model = BacktestRun::class;

    public function definition(): array
    {
        $start = now('UTC')->subDays(30)->startOfDay();
        $end = now('UTC')->startOfDay();

        return [
            'user_id' => User::factory(),
            'bot_id' => null,
            'name' => 'Test backtest',
            'exchange' => 'binance',
            'symbol' => 'BTC/USDT',
            'strategy_key' => 'simple-grid',
            'timeframe' => '15m',
            'start_at' => $start,
            'end_at' => $end,
            'capital_allocation' => '1000',
            'fee_rate' => '0.001',
            'config_snapshot' => [
                'bot' => [
                    'id' => null,
                    'name' => 'Test backtest',
                    'exchange' => 'binance',
                    'symbol' => 'BTC/USDT',
                    'strategy_key' => 'simple-grid',
                    'capital_allocation' => '1000',
                    'fee_rate' => '0.001',
                ],
                'strategy' => [
                    'key' => 'simple-grid',
                    'configuration' => [
                        'center_price' => '100',
                        'spacing_type' => 'arithmetic',
                        'spacing_value' => '1',
                        'order_quantity' => '1',
                        'levels_per_side' => 2,
                    ],
                ],
                'risk' => [
                    'max_position_notional' => '1000',
                    'max_open_orders' => 20,
                    'daily_loss_limit' => '100',
                ],
                'period' => [
                    'timeframe' => '15m',
                    'start_at' => $start->toIso8601String(),
                    'end_at' => $end->toIso8601String(),
                ],
            ],
            'status' => BacktestStatus::Pending,
            'end_reason' => null,
            'candles_processed' => 0,
            'progress' => 0,
            'metrics' => null,
            'equity_curve' => null,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function forBot(Bot $bot): static
    {
        return $this->state(fn () => [
            'user_id' => $bot->user_id,
            'bot_id' => $bot->id,
            'exchange' => $bot->exchange,
            'symbol' => $bot->symbol,
            'strategy_key' => $bot->strategy_key,
            'capital_allocation' => $bot->capital_allocation,
            'fee_rate' => $bot->fee_rate,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => BacktestStatus::Completed,
            'end_reason' => BacktestEndReason::RangeEnd,
            'progress' => 100,
            'candles_processed' => 100,
            'metrics' => [],
            'equity_curve' => [],
            'finished_at' => now('UTC'),
        ]);
    }
}
