<?php

namespace Database\Factories;

use App\Models\ExchangeCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExchangeCredentialFactory extends Factory
{
    protected $model = ExchangeCredential::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'exchange' => 'binance',
            'api_key' => 'test-api-key-'.fake()->uuid(),
            'api_secret' => 'test-api-secret-'.fake()->uuid(),
            'passphrase' => null,
            'testnet' => true,
        ];
    }
}
