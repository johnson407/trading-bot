<?php

namespace Database\Factories;

use App\Domain\Bot\Enums\BotMode;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BotFactory extends Factory
{
    protected $model = Bot::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'exchange_credential_id' => null,
            'name' => 'BTC grid '.fake()->unique()->numberBetween(1, 9999),
            'exchange' => 'binance',
            'symbol' => 'BTC/USDT',
            'strategy_key' => 'simple-grid',
            'mode' => BotMode::Paper,
            'status' => BotStatus::Idle,
            'capital_allocation' => '1000',
            'base_inventory' => '0',
            'quote_inventory' => '0',
            'average_entry_price' => '0',
            'realized_pnl' => '0',
            'fees_paid' => '0',
            'fee_rate' => '0',
            'tick_interval_seconds' => max(1, (int) config('trading.tick.interval_seconds')),
            'consecutive_failures' => 0,
            'settings' => [],
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => BotStatus::Running]);
    }

    public function live(): static
    {
        return $this->state(fn () => ['mode' => BotMode::Live]);
    }
}
