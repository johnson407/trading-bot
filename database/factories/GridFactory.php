<?php

namespace Database\Factories;

use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Grid\Enums\GridStatus;
use App\Models\Bot;
use App\Models\Grid;
use Illuminate\Database\Eloquent\Factories\Factory;

class GridFactory extends Factory
{
    protected $model = Grid::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'center_price' => '50000',
            'spacing_type' => GridSpacingType::Arithmetic,
            'level_count' => 6,
            'spacing_value' => '100',
            'status' => GridStatus::Active,
        ];
    }
}
