<?php

namespace Database\Factories;

use App\Domain\Risk\Enums\RiskScope;
use App\Models\Bot;
use App\Models\RiskLimit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiskLimitFactory extends Factory
{
    protected $model = RiskLimit::class;

    public function definition(): array
    {
        return [
            'scope' => RiskScope::User,
            'user_id' => User::factory(),
            'bot_id' => null,
            'max_position_notional' => '1000',
            'max_open_orders' => 20,
            'daily_loss_limit' => '100',
            'day_start' => now('UTC')->startOfDay(),
            'day_start_realized_pnl' => '0',
        ];
    }

    public function forBot(Bot $bot): static
    {
        return $this->state(fn () => [
            'scope' => RiskScope::Bot,
            'user_id' => $bot->user_id,
            'bot_id' => $bot->id,
        ]);
    }
}
