<?php

namespace Database\Factories;

use App\Domain\Order\Enums\OrderSide;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FillFactory extends Factory
{
    protected $model = Fill::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'order_id' => Order::factory(),
            'exchange' => 'binance',
            'exchange_fill_id' => 'fill-'.Str::uuid(),
            'side' => OrderSide::Buy,
            'price' => '50000',
            'quantity' => '0.001',
            'fee' => '0',
            'fee_currency' => 'USDT',
            'filled_at' => now(),
        ];
    }
}
