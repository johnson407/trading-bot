<?php

namespace App\Actions;

use App\Models\ExchangeCredential;
use App\Models\User;

class SaveExchangeCredential
{
    /**
     * Create or replace the user's credential for an exchange.
     */
    public function handle(
        User $user,
        string $exchange,
        string $apiKey,
        string $apiSecret,
        ?string $passphrase = null,
        bool $testnet = true,
    ): ExchangeCredential {
        $credential = ExchangeCredential::query()->updateOrCreate(
            ['user_id' => $user->id, 'exchange' => $exchange],
            [
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
                'passphrase' => $passphrase,
                'testnet' => $testnet,
            ],
        );

        return $credential->refresh();
    }
}
