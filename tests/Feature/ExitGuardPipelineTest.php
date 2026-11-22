<?php

use App\Domain\Bot\BotTickPipeline;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Support\Decimal;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function exitPipelineBot(string $price = '94'): array
{
    $bot = Bot::factory()
        ->for(User::factory())
        ->running()
        ->create([
            'symbol' => 'BTC/USDT',
            'strategy_key' => 'simple-grid',
            'base_inventory' => '1',
            'average_entry_price' => '100',
            'quote_inventory' => '1000',
            'fee_rate' => '0',
            'stop_loss_percent' => '5',
            'take_profit_percent' => null,
            'range_low' => null,
            'range_high' => null,
        ]);

    $fake = new FakeExchangeClient(price: $price);
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
        new PaperExchangeClient($fake),
        $fake,
    ));

    return [$bot, $fake];
}

it('pauses before the strategy runs when the mark crosses the stop-loss', function () {
    [$bot, $fake] = exitPipelineBot('94');

    $result = app(BotTickPipeline::class)->tick($bot);

    expect($result->status)->toBe('skipped')
        ->and($result->ordersPlaced)->toBe(0)
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($fake->placeCalls)->toBe([])
        ->and(Order::query()->where('bot_id', $bot->id)->count())->toBe(0)
        ->and(StrategyConfig::query()->where('bot_id', $bot->id)->count())->toBe(0)
        ->and($bot->riskEvents()->where('type', 'risk.stop_loss_triggered')->count())->toBe(1);
});

it('does not retrigger when a paused bot is ticked again', function () {
    [$bot] = exitPipelineBot('94');

    $pipeline = app(BotTickPipeline::class);
    $pipeline->tick($bot);
    $pipeline->tick($bot->fresh());

    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($bot->riskEvents()->where('type', 'risk.stop_loss_triggered')->count())->toBe(1)
        ->and($bot->activityLogs()->where('type', 'bot.exit_guard_triggered')->count())->toBe(1);
});

it('pauses again when an explicitly resumed bot is still beyond the threshold', function () {
    [$bot] = exitPipelineBot('94');

    $pipeline = app(BotTickPipeline::class);
    $pipeline->tick($bot);

    $bot->fresh()->forceFill(['status' => BotStatus::Running])->save();

    $pipeline->tick($bot->fresh());

    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($bot->riskEvents()->where('type', 'risk.stop_loss_triggered')->count())->toBe(2);
});

it('pauses, cancels, preserves inventory, and leaves one unread inbox alert', function () {
    [$bot] = exitPipelineBot('94');
    $order = Order::factory()->for($bot)->create(['status' => 'open']);

    $this->actingAs($bot->user);

    $result = app(BotTickPipeline::class)->tick($bot);
    $fresh = $bot->fresh();
    $alert = Alert::query()->where('user_id', $bot->user_id)->sole();

    expect($result->status)->toBe('skipped')
        ->and($fresh->status)->toBe(BotStatus::Paused)
        ->and($order->fresh()->status->value)->toBe('canceled')
        ->and(Decimal::of($fresh->base_inventory)->toString())->toBe('1')
        ->and(Decimal::of($fresh->quote_inventory)->toString())->toBe('1000')
        ->and($alert->type)->toBe('risk.stop_loss_triggered')
        ->and($alert->severity)->toBe('critical')
        ->and($alert->bot_id)->toBe($bot->id)
        ->and($alert->read_at)->toBeNull();

    $this->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Alerts/Index')
            ->has('alerts.data', 1)
            ->where('alerts.data.0.id', $alert->id)
            ->where('alerts.data.0.type', 'risk.stop_loss_triggered')
            ->where('alerts.data.0.read_at', null)
            ->where('auth.unread_alerts', 1)
            ->etc());
});
