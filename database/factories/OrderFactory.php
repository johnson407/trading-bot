<?php

namespace Database\Factories;

use App\Domain\Bot\Enums\BotMode;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderType;
use App\Models\Bot;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'grid_id' => null,
            'grid_level_id' => null,
            'client_order_id' => 'test-'.Str::uuid(),
            'exchange_order_id' => null,
            'mode' => BotMode::Paper,
            'side' => OrderSide::Buy,
            'type' => OrderType::Limit,
            'price' => '50000',
            'quantity' => '0.001',
            'filled_quantity' => '0',
            'status' => OrderStatus::Open,
            'requested_at' => now(),
            'opened_at' => now(),
        ];
    }
}
