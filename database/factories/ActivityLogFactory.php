<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bot_id' => null,
            'type' => 'test.activity',
            'severity' => 'info',
            'message' => 'An activity',
            'context' => [],
            'created_at' => now(),
        ];
    }
}
