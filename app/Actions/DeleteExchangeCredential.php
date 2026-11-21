<?php

namespace App\Actions;

use App\Models\ExchangeCredential;

class DeleteExchangeCredential
{
    public function handle(ExchangeCredential $credential): void
    {
        $credential->delete();
    }
}
