<?php

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Portfolio\Exceptions\NegativeInventoryException;
use App\Domain\Portfolio\InventoryLedger;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ledgerFill(Bot $bot, OrderSide $side, string $price, string $quantity, string $fee = '0'): Fill
{
    $order = Order::factory()->for($bot)->create([
        'side' => $side,
        'price' => $price,
        'quantity' => $quantity,
        'filled_quantity' => $quantity,
        'status' => OrderStatus::Filled,
    ]);

    return Fill::factory()->for($bot)->for($order)->create([
        'side' => $side,
        'price' => $price,
        'quantity' => $quantity,
        'fee' => $fee,
    ]);
}

it('tracks average-cost inventory and exact realized pnl across fills', function () {
    $bot = Bot::factory()->create([
        'base_inventory' => '0',
        'quote_inventory' => '1000',
        'average_entry_price' => '0',
        'realized_pnl' => '0',
        'fees_paid' => '0',
    ]);
    $ledger = app(InventoryLedger::class);

    $ledger->apply($bot, ledgerFill($bot, OrderSide::Buy, '100', '1'));
    expect(Decimal::of($bot->fresh()->average_entry_price)->toString())->toBe('100');

    $ledger->apply($bot, ledgerFill($bot, OrderSide::Buy, '200', '1'));
    $bot->refresh();

    expect(Decimal::of($bot->base_inventory)->toString())->toBe('2')
        ->and(Decimal::of($bot->quote_inventory)->toString())->toBe('700')
        ->and(Decimal::of($bot->average_entry_price)->toString())->toBe('150');

    $ledger->apply($bot, ledgerFill($bot, OrderSide::Sell, '250', '1', '1'));
    $ledger->apply($bot, ledgerFill($bot, OrderSide::Sell, '100', '1'));
    $bot->refresh();

    expect(Decimal::of($bot->base_inventory)->toString())->toBe('0')
        ->and(Decimal::of($bot->quote_inventory)->toString())->toBe('1049')
        ->and(Decimal::of($bot->average_entry_price)->toString())->toBe('0')
        ->and(Decimal::of($bot->realized_pnl)->toString())->toBe('49')
        ->and(Decimal::of($bot->fees_paid)->toString())->toBe('1')
        ->and($bot->inventoryLedgerEntries()->count())->toBe(4);
});

it('computes unrealized pnl from the mark price', function () {
    $bot = Bot::factory()->create([
        'base_inventory' => '2',
        'average_entry_price' => '150',
    ]);

    expect(app(InventoryLedger::class)->unrealizedPnl($bot, '200')->toString())->toBe('100')
        ->and(app(InventoryLedger::class)->unrealizedPnl($bot, '100')->toString())->toBe('-100');
});

it('rejects a sale larger than the base position', function () {
    $bot = Bot::factory()->create(['base_inventory' => '0.5']);

    expect(fn () => app(InventoryLedger::class)->apply($bot, ledgerFill($bot, OrderSide::Sell, '100', '1')))
        ->toThrow(NegativeInventoryException::class);

    expect($bot->fresh()->base_inventory)->toBe('0.5000000000000000')
        ->and($bot->inventoryLedgerEntries()->count())->toBe(0);
});
