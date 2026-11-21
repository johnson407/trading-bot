<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\StrategyConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

class StrategyConfigFactory extends Factory
{
    protected $model = StrategyConfig::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'strategy_key' => 'simple-grid',
            'configuration' => [
                'center_price' => '50000',
                'level_count' => 6,
                'spacing_type' => 'arithmetic',
                'spacing_value' => '100',
                'order_quantity' => '0.001',
            ],
            'state' => null,
            'state_version' => 1,
        ];
    }
}
