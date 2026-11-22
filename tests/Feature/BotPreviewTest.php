<?php

use App\Actions\StartBot;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Grid;
use App\Models\Order;
use App\Models\RiskLimit;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function previewPayload(array $overrides = []): array
{
    return array_replace([
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'capital_allocation' => '1000',
        'center_price' => '50000',
        'level_count' => 3,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'order_quantity' => '0.001',
    ], $overrides);
}

function fakeMarket(): FakeExchangeClient
{
    $fake = new FakeExchangeClient(price: '50000');

    app()->instance(
        ExchangeClientFactory::class,
        new ExchangeClientFactory(overrides: ['binance' => $fake]),
    );

    return $fake;
}

it('redirects guests away from the preview', function () {
    $this->post(route('bots.preview'), previewPayload())->assertRedirect(route('login'));
});

it('does not write records when previewed repeatedly', function () {
    $user = User::factory()->create();
    fakeMarket();

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)
            ->postJson(route('bots.preview'), previewPayload())
            ->assertOk()
            ->assertJsonPath('plan.order_count', 6);
    }

    expect(Bot::count())->toBe(0)
        ->and(Grid::count())->toBe(0)
        ->and(Order::count())->toBe(0)
        ->and(RiskLimit::count())->toBe(0);
});

it('returns the plan, limits and market data without writing records', function () {
    $user = User::factory()->create();
    fakeMarket();

    $response = $this->actingAs($user)->postJson(route('bots.preview'), previewPayload());

    $response->assertOk()
        ->assertJsonPath('plan.strategy_key', 'simple-grid')
        ->assertJsonPath('plan.order_count', 6)
        ->assertJsonPath('plan.buy_notional', '149.4')
        ->assertJsonPath('plan.sell_notional', '150.6')
        ->assertJsonPath('limits.max_open_orders', 20)
        ->assertJsonPath('market.last', '50000')
        ->assertJsonCount(6, 'plan.levels')
        ->assertJsonStructure([
            'plan' => ['levels', 'errors', 'warnings'],
            'limits' => ['max_position_notional', 'max_open_orders', 'daily_loss_limit'],
            'market' => ['last', 'price_precision', 'amount_precision', 'min_amount', 'min_notional'],
            'values',
        ]);

    expect(Bot::count())->toBe(0)
        ->and(Grid::count())->toBe(0)
        ->and(Order::count())->toBe(0)
        ->and(RiskLimit::count())->toBe(0);
});

it('returns a preview error instead of a validation failure for invalid geometry', function () {
    $user = User::factory()->create();
    fakeMarket();

    $this->actingAs($user)
        ->postJson(route('bots.preview'), previewPayload([
            'spacing_type' => 'geometric',
            'spacing_value' => '1',
        ]))
        ->assertOk()
        ->assertJsonPath('plan.errors.0.field', 'spacing_value')
        ->assertJsonCount(0, 'plan.levels');
});

it('derives concrete values from a quick-setup preset', function () {
    $user = User::factory()->create();
    fakeMarket();

    $this->actingAs($user)
        ->postJson(route('bots.preview'), [
            'preset_key' => 'balanced',
            'exchange' => 'binance',
            'symbol' => 'BTC/USDT',
            'capital_allocation' => '1000',
            'center_price' => '50000',
        ])
        ->assertOk()
        ->assertJsonPath('values.strategy_key', 'simple-grid')
        ->assertJsonPath('values.level_count', 6)
        ->assertJsonPath('values.spacing_type', 'geometric')
        ->assertJsonPath('values.spacing_value', '0.004')
        ->assertJsonPath('values.order_quantity', '0.0026666666666667')
        ->assertJsonPath('plan.order_count', 12)
        ->assertJsonPath('plan.errors', []);
});

it('saves the grid that the preview described', function () {
    $user = User::factory()->create();
    fakeMarket();

    $payload = previewPayload([
        'level_count' => 4,
        'order_quantity' => '0.002',
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('bots.preview'), $payload)
        ->assertOk();

    $preview = $response->json('plan');
    $warnings = $response->json('warnings');

    expect(array_column($warnings, 'code'))->toContain('plan.unfunded_sells');

    $this->actingAs($user)
        ->post(route('bots.store'), $payload + ['name' => 'Preview agreement'])
        ->assertRedirect();

    $bot = Bot::query()->firstOrFail();
    app(StartBot::class)->handle($bot->fresh());
    app(BotTickPipeline::class)->tick($bot->fresh());

    $grid = $bot->fresh()->activeGrid;
    $levels = $grid->levels->sortBy('sequence')->values();
    $orders = Order::query()->where('bot_id', $bot->id)->get();
    $ordersBySequence = $orders->keyBy(
        fn (Order $order) => $grid->levels->firstWhere('id', $order->grid_level_id)?->sequence,
    );

    $placedLevels = array_values(array_filter(
        $preview['levels'],
        fn (array $level) => $level['side'] === 'buy',
    ));

    expect($levels)->toHaveCount(count($preview['levels']))
        ->and($orders)->toHaveCount(count($placedLevels));

    $buyNotional = Decimal::zero();

    foreach ($preview['levels'] as $level) {
        $order = $ordersBySequence[$level['sequence']] ?? null;

        if ($level['side'] === 'sell') {
            expect($order)->toBeNull();

            continue;
        }

        expect($order)->not->toBeNull()
            ->and($order->side->value)->toBe($level['side'])
            ->and(Decimal::of($order->price)->equals($level['price']))->toBeTrue()
            ->and(Decimal::of($order->quantity)->equals($level['quantity']))->toBeTrue();

        $buyNotional = $buyNotional->add(Decimal::of($level['notional']));
    }

    expect($buyNotional->equals($preview['buy_notional']))->toBeTrue();
});

it('throttles preview requests', function () {
    $user = User::factory()->create();
    fakeMarket();

    foreach (range(1, 120) as $attempt) {
        $this->actingAs($user)->postJson(route('bots.preview'), previewPayload())->assertOk();
    }

    $this->actingAs($user)->postJson(route('bots.preview'), previewPayload())->assertTooManyRequests();
});
