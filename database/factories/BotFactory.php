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

    /**
     * Engine-owned columns are intentionally outside the model's $fillable
     * list. Test fixtures still need to seed them, so the factory force-fills
     * the expanded definition instead of relying on mass assignment.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = [])
    {
        $model = parent::newModel([]);
        $model->forceFill($attributes);

        return $model;
    }

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
            'desired_status' => BotStatus::Idle,
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

    public function withExitRules(): static
    {
        return $this->state(fn () => [
            'stop_loss_percent' => '4',
            'take_profit_percent' => '9',
            'range_low' => '80000',
            'range_high' => '120000',
        ]);
    }

    public function withoutExitRules(): static
    {
        return $this->state(fn () => [
            'stop_loss_percent' => null,
            'take_profit_percent' => null,
            'range_low' => null,
            'range_high' => null,
        ]);
    }
}
