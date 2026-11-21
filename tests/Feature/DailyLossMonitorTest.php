<?php

use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Risk\DailyLossMonitor;
use App\Models\Bot;
use App\Models\Order;
use App\Models\RiskLimit;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function lossBot(User $user, string $pnl, string $limit = '100', string $dayStartPnl = '0'): Bot
{
    $bot = Bot::factory()->for($user)->running()->create(['realized_pnl' => $pnl]);
    RiskLimit::factory()->forBot($bot)->create([
        'daily_loss_limit' => $limit,
        'day_start' => now('UTC')->startOfDay(),
        'day_start_realized_pnl' => $dayStartPnl,
    ]);

    return $bot;
}

function userLossLimit(User $user, string $limit = '100'): RiskLimit
{
    return RiskLimit::factory()->create([
        'scope' => 'user',
        'user_id' => $user->id,
        'bot_id' => null,
        'daily_loss_limit' => $limit,
        'day_start' => now('UTC')->startOfDay(),
        'day_start_realized_pnl' => '0',
    ]);
}

it('pauses a bot and cancels orders when its daily loss limit is breached', function () {
    $user = User::factory()->create();
    $bot = lossBot($user, '-150');
    $order = Order::factory()->for($bot)->create(['status' => 'open']);
    userLossLimit($user, '1000');

    app(DailyLossMonitor::class)->evaluate($bot);

    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($order->fresh()->status->value)->toBe('canceled')
        ->and($bot->riskEvents()->where('type', 'risk.daily_loss_limit')->count())->toBe(1);
});

it('pauses every user bot when the global daily loss limit is breached', function () {
    $user = User::factory()->create();
    $first = lossBot($user, '-60', '1000');
    $second = lossBot($user, '-60', '1000');
    userLossLimit($user, '100');

    app(DailyLossMonitor::class)->evaluate($first);

    expect($first->fresh()->status)->toBe(BotStatus::Paused)
        ->and($second->fresh()->status)->toBe(BotStatus::Paused);
});

it('resets the loss baseline at the start of a new UTC day', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->create(['realized_pnl' => '-150']);
    RiskLimit::factory()->forBot($bot)->create([
        'daily_loss_limit' => '100',
        'day_start' => now('UTC')->subDay()->startOfDay(),
        'day_start_realized_pnl' => '0',
    ]);
    userLossLimit($user, '1000');

    app(DailyLossMonitor::class)->evaluate($bot);

    expect($bot->fresh()->status)->toBe(BotStatus::Running)
        ->and($bot->riskLimit->fresh()->day_start_realized_pnl)->toBe('-150.0000000000000000');
});

it('pauses on unrealized losses at the supplied mark price', function () {
    $user = User::factory()->create();
    $bot = lossBot($user, '0', '15');
    $bot->forceFill([
        'symbol' => 'BTC/USDT',
        'base_inventory' => '1',
        'average_entry_price' => '100',
    ])->save();
    userLossLimit($user, '1000');

    app(DailyLossMonitor::class)->evaluate($bot, '80');

    expect($bot->fresh()->status)->toBe(BotStatus::Paused);
});

it('uses each bot own mark when summing the user daily loss', function () {
    $user = User::factory()->create();

    $btc = lossBot($user, '0', '1000');
    $btc->forceFill([
        'symbol' => 'BTC/USDT',
        'base_inventory' => '1',
        'average_entry_price' => '100',
    ])->save();

    $eth = lossBot($user, '0', '1000');
    $eth->forceFill([
        'symbol' => 'ETH/USDT',
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'settings' => ['mark_price' => '100'],
    ])->save();

    userLossLimit($user, '25');

    app(DailyLossMonitor::class)->evaluate($btc, '80');

    expect($btc->fresh()->status)->toBe(BotStatus::Running)
        ->and($eth->fresh()->status)->toBe(BotStatus::Running);
});

it('stores the summed user baseline when the UTC day resets', function () {
    $user = User::factory()->create();
    $first = lossBot($user, '-80', '1000');
    $second = lossBot($user, '-40', '1000');
    $limit = userLossLimit($user, '100');
    $limit->forceFill([
        'day_start' => now('UTC')->subDay()->startOfDay(),
        'day_start_realized_pnl' => '0',
    ])->save();

    app(DailyLossMonitor::class)->evaluate($first);

    expect($limit->fresh()->day_start_realized_pnl)->toBe('-120.0000000000000000')
        ->and($first->fresh()->status)->toBe(BotStatus::Running)
        ->and($second->fresh()->status)->toBe(BotStatus::Running);
});

it('checks unrealized daily loss with the tick mark price before placing orders', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->create([
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'realized_pnl' => '0',
        'quote_inventory' => '1000',
    ]);
    RiskLimit::factory()->forBot($bot)->create([
        'daily_loss_limit' => '15',
        'day_start' => now('UTC')->startOfDay(),
        'day_start_realized_pnl' => '0',
    ]);
    userLossLimit($user, '1000');

    $fake = new FakeExchangeClient(price: '80');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, $fake));

    $result = app(BotTickPipeline::class)->tick($bot);

    expect($result->status)->toBe('skipped')
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($fake->placeCalls)->toBe([]);
});

it('does not resume an already paused bot when its loss recovers', function () {
    $user = User::factory()->create();
    $bot = lossBot($user, '-150');
    $bot->forceFill(['status' => BotStatus::Paused])->save();
    userLossLimit($user, '1000');

    app(DailyLossMonitor::class)->evaluate($bot);
    $bot->forceFill(['realized_pnl' => '0'])->save();
    app(DailyLossMonitor::class)->evaluate($bot->fresh());

    expect($bot->fresh()->status)->toBe(BotStatus::Paused);
});
