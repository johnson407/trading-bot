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
            'config_snapshot' => $this->snapshot($start, $end),
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

    /**
     * @return array<string, mixed>
     */
    private function snapshot(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return [
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
            'exit' => [
                'stop_loss_percent' => null,
                'take_profit_percent' => null,
                'range_low' => null,
                'range_high' => null,
            ],
            'period' => [
                'timeframe' => '15m',
                'start_at' => $start->format(\DateTimeInterface::ATOM),
                'end_at' => $end->format(\DateTimeInterface::ATOM),
            ],
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

    public function withExitRules(): static
    {
        return $this->state(function (array $attributes): array {
            $start = $attributes['start_at'] ?? now('UTC')->subDays(30)->startOfDay();
            $end = $attributes['end_at'] ?? now('UTC')->startOfDay();

            return [
                'config_snapshot' => array_replace_recursive(
                    $this->snapshot($start, $end),
                    [
                        'exit' => [
                            'stop_loss_percent' => '4',
                            'take_profit_percent' => '9',
                            'range_low' => '80000',
                            'range_high' => '120000',
                        ],
                    ],
                ),
            ];
        });
    }

    public function completedSource(): static
    {
        return $this->completed()
            ->withExitRules()
            ->state(fn () => [
                'metrics' => [
                    'final_equity' => '1050',
                    'net_pnl' => '50',
                    'return_percent' => '5',
                    'fees' => '2.5',
                    'fill_count' => 12,
                    'max_drawdown' => '15',
                    'max_drawdown_percent' => '1.5',
                    'candles_processed' => 100,
                ],
                'equity_curve' => [
                    ['at' => now('UTC')->subDays(2)->toIso8601String(), 'equity' => '1000'],
                    ['at' => now('UTC')->subDay()->toIso8601String(), 'equity' => '1050'],
                ],
            ]);
    }
}
