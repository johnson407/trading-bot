<?php

use App\Actions\CreateBot;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Risk\DailyLossMonitor;
use App\Domain\Risk\RiskGuard;
use App\Domain\Risk\RiskLimitService;
use App\Models\Bot;
use App\Models\Order;
use App\Models\RiskLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('does not create a shadowing bot risk row when a bot is created', function () {
    $user = User::factory()->create();

    app(CreateBot::class)->handle($user, [
        'name' => 'No shadow',
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'mode' => 'paper',
        'capital_allocation' => '1000',
        'fee_rate' => '0',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 1,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
        ],
    ]);

    expect(RiskLimit::query()->where('scope', 'bot')->count())->toBe(0)
        ->and(RiskLimit::query()->where('scope', 'user')->where('user_id', $user->id)->count())->toBe(1);
});

it('applies user risk limits to a bot that has no override', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    app(RiskLimitService::class)->updateForUser($user, [
        'max_position_notional' => '100',
        'max_open_orders' => '1',
        'daily_loss_limit' => '50',
    ]);

    $effective = app(RiskLimitService::class)->forBot($bot);

    expect($effective->scope->value)->toBe('user')
        ->and($effective->max_open_orders)->toBe(1);

    Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $decision = app(RiskGuard::class)->checkOrder($bot, OrderSide::Buy, '10', '1');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->type)->toBe('risk.open_order_limit');
});

it('keeps a per-bot daily loss baseline when the bot uses user limits', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->create(['realized_pnl' => '-150']);
    app(RiskLimitService::class)->updateForUser($user, [
        'max_position_notional' => '5000',
        'max_open_orders' => '20',
        'daily_loss_limit' => '100',
    ]);

    app(DailyLossMonitor::class)->evaluate($bot);

    $bot->refresh();

    expect($bot->status->value)->toBe('paused')
        ->and($bot->settings['daily_loss_baseline']['day'])->toBe(now('UTC')->toDateString())
        ->and($bot->settings['daily_loss_baseline']['realized'])->toBe('-150.0000000000000000');
});

it('lets an owner edit account risk limits', function () {
    $this->withoutVite();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('risk-limits.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RiskLimits/Edit')
            ->has('limits')
            ->etc());

    $this->actingAs($user)->patch(route('risk-limits.update'), [
        'max_position_notional' => '2500',
        'max_open_orders' => '8',
        'daily_loss_limit' => '250',
    ])->assertRedirect(route('risk-limits.edit'));

    $limit = app(RiskLimitService::class)->forUser($user);

    expect($limit->max_position_notional)->toBe('2500.0000000000000000')
        ->and($limit->max_open_orders)->toBe(8)
        ->and($limit->daily_loss_limit)->toBe('250.0000000000000000');
});

it('validates and isolates risk limit updates', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    app(RiskLimitService::class)->forUser($other);

    $this->actingAs($user)->patch(route('risk-limits.update'), [
        'max_position_notional' => '-1',
        'max_open_orders' => '0',
        'daily_loss_limit' => 'x',
    ])->assertSessionHasErrors(['max_position_notional', 'max_open_orders', 'daily_loss_limit']);

    expect(app(RiskLimitService::class)->forUser($other)->max_open_orders)->toBe(20);
});
