<?php

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Risk\RiskGuard;
use App\Models\Bot;
use App\Models\Order;
use App\Models\RiskLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function guardBot(string $base = '0', string $maxNotional = '1000', int $maxOrders = 20): Bot
{
    $bot = Bot::factory()->create(['base_inventory' => $base]);
    RiskLimit::factory()->forBot($bot)->create([
        'max_position_notional' => $maxNotional,
        'max_open_orders' => $maxOrders,
    ]);

    return $bot;
}

it('allows orders inside the position and open-order limits', function () {
    $bot = guardBot();

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Buy, '100', '5');

    expect($decision->allowed)->toBeTrue()
        ->and($bot->riskEvents()->count())->toBe(0);
});

it('allows an order exactly on the position boundary', function () {
    $bot = guardBot();

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Buy, '100', '10');

    expect($decision->allowed)->toBeTrue();
});

it('blocks an order over the position limit and records a risk event', function () {
    $bot = guardBot();

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Buy, '100', '10.0000000000000001');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->type)->toBe('risk.position_limit')
        ->and($bot->riskEvents()->where('type', 'risk.position_limit')->count())->toBe(1);
});

it('blocks when the open-order limit would be exceeded', function () {
    $bot = guardBot(maxOrders: 2);
    Order::factory()->for($bot)->count(2)->create(['status' => OrderStatus::Open]);

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Buy, '100', '1');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->type)->toBe('risk.open_order_limit')
        ->and($decision->context['open_orders'])->toBe(2);
});

it('allows the open-order boundary and ignores terminal orders', function () {
    $bot = guardBot(maxOrders: 2);
    Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);
    Order::factory()->for($bot)->create(['status' => OrderStatus::Filled]);

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Buy, '100', '1');

    expect($decision->allowed)->toBeTrue();
});

it('blocks a sell that exceeds the base inventory', function () {
    $bot = guardBot(base: '0');

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Sell, '110', '1');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->type)->toBe('risk.insufficient_inventory')
        ->and($decision->context['base_inventory'])->toBe('0')
        ->and($decision->context['available'])->toBe('0')
        ->and($bot->riskEvents()->where('type', 'risk.insufficient_inventory')->count())->toBe(0);
});

it('allows a sell covered by the base inventory', function () {
    $bot = guardBot(base: '1');

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Sell, '110', '1');

    expect($decision->allowed)->toBeTrue();
});

it('counts open buy orders toward the position limit', function () {
    $bot = guardBot(maxNotional: '200');
    Order::factory()->for($bot)->create([
        'status' => OrderStatus::Open,
        'side' => OrderSide::Buy,
        'price' => '100',
        'quantity' => '1',
        'filled_quantity' => '0',
    ]);

    $blocked = app(RiskGuard::class)->checkOrder($bot, OrderSide::Buy, '100', '1.5');
    $allowed = app(RiskGuard::class)->checkOrder($bot, OrderSide::Buy, '100', '1');

    expect($blocked->allowed)->toBeFalse()
        ->and($blocked->type)->toBe('risk.position_limit')
        ->and($blocked->context['projected_notional'])->toBe('250')
        ->and($allowed->allowed)->toBeTrue();
});

it('blocks a sell when the base inventory is already committed to open sells', function () {
    $bot = guardBot(base: '1');
    Order::factory()->for($bot)->create([
        'status' => OrderStatus::Open,
        'side' => OrderSide::Sell,
        'price' => '110',
        'quantity' => '1',
        'filled_quantity' => '0',
    ]);

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Sell, '120', '1');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->type)->toBe('risk.insufficient_inventory')
        ->and($decision->context['reserved_sell_quantity'])->toBe('1')
        ->and($decision->context['available'])->toBe('0');
});
