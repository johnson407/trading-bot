<?php

namespace Database\Factories;

use App\Models\EquitySnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquitySnapshot>
 */
class EquitySnapshotFactory extends Factory
{
    protected $model = EquitySnapshot::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'captured_at' => now()->startOfMinute(),
            'equity' => '10000.00000000',
            'quote_value' => '10000.00000000',
            'base_value' => '0.00000000',
            'realized_pnl' => '0.00000000',
            'unrealized_pnl' => '0.00000000',
            'fees_paid' => '0.00000000',
            'bot_count' => 1,
        ];
    }

    public function at(string $capturedAt): static
    {
        return $this->state(fn (): array => ['captured_at' => $capturedAt]);
    }
}
