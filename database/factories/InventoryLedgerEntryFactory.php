<?php

namespace Database\Factories;

use App\Domain\Order\Enums\OrderSide;
use App\Models\Bot;
use App\Models\InventoryLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryLedgerEntryFactory extends Factory
{
    protected $model = InventoryLedgerEntry::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'fill_id' => null,
            'side' => OrderSide::Buy,
            'quantity' => '0.001',
            'price' => '50000',
            'base_delta' => '0.001',
            'quote_delta' => '-50',
            'base_balance' => '0.001',
            'quote_balance' => '950',
            'average_entry_price' => '50000',
            'realized_pnl' => '0',
            'created_at' => now(),
        ];
    }
}
