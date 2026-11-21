<?php

namespace Database\Factories;

use App\Domain\Grid\Enums\GridLevelState;
use App\Domain\Order\Enums\OrderSide;
use App\Models\Grid;
use App\Models\GridLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

class GridLevelFactory extends Factory
{
    protected $model = GridLevel::class;

    public function definition(): array
    {
        return [
            'grid_id' => Grid::factory(),
            'sequence' => fake()->unique()->numberBetween(1, 100000),
            'price' => '50000',
            'side' => OrderSide::Buy,
            'state' => GridLevelState::Idle,
        ];
    }
}
