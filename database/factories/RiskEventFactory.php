<?php

namespace Database\Factories;

use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiskEventFactory extends Factory
{
    protected $model = RiskEvent::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bot_id' => null,
            'type' => 'test.event',
            'severity' => 'warning',
            'message' => 'A risk event',
            'context' => [],
            'created_at' => now(),
        ];
    }
}
