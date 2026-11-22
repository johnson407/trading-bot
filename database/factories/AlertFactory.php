<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bot_id' => null,
            'type' => 'risk.daily_loss_limit',
            'severity' => 'warning',
            'title' => 'Test alert',
            'message' => 'An alert',
            'context' => [],
            'source_type' => null,
            'source_id' => null,
            'dedupe_key' => hash('sha256', fake()->uuid()),
            'dedupe_bucket' => intdiv(now()->getTimestamp(), max(1, (int) config('alerts.dedupe_window_seconds', 900))),
            'read_at' => null,
            'created_at' => now(),
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }
}
