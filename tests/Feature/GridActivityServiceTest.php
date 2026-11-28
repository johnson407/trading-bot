<?php

use App\Domain\Exchange\ValueObjects\Candle;
use App\Domain\Grid\Enums\GridStatus;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\CandleProvider;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\GridActivity\GridActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function gridActivityService(FakeExchangeClient $fake): GridActivityService
{
    $fake->candles = [new Candle(now()->subHour()->toDateTimeImmutable(), Decimal::of('50000'))];

    return new GridActivityService(
        new CandleProvider(new ExchangeClientFactory(overrides: ['binance' => $fake])),
    );
}

afterEach(function () {
    Carbon::setTestNow();
});

it('builds a dynamic-grid activity payload with all-cause cancels and churn metrics', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $fake = new FakeExchangeClient;
    $service = gridActivityService($fake);
    $bot = Bot::factory()->running()->create([
        'strategy_key' => 'dynamic-grid',
        'last_successful_tick_at' => now(),
    ]);
    StrategyConfig::factory()->create([
        'bot_id' => $bot->id,
        'strategy_key' => 'dynamic-grid',
        'configuration' => [
            'spacing_type' => 'dynamic',
            'spacing_value' => '0',
            'spacing_hysteresis_percent' => '35',
        ],
    ]);
    $grid = Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Active,
        'center_price' => '50000',
        'spacing_value' => '120',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subMinutes(1),
    ]);
    GridLevel::factory()->create([
        'grid_id' => $grid->id,
        'sequence' => 1,
        'price' => '49900',
        'side' => OrderSide::Buy,
    ]);
    Fill::factory()->create([
        'bot_id' => $bot->id,
        'price' => '49900',
        'filled_at' => now()->subHour(),
    ]);
    Order::factory()->create([
        'bot_id' => $bot->id,
        'status' => OrderStatus::Canceled,
        'closed_at' => now()->subMinutes(30),
    ]);

    $activity = $service->build($bot, '24h');

    expect($activity['range'])->toBe('24h')
        ->and($activity['available_ranges'])->toBe(['1h', '6h', '24h', '7d'])
        ->and($activity['price']['available'])->toBeTrue()
        ->and($activity['price']['timeframe'])->toBe('5m')
        ->and($activity['grids'])->toHaveCount(1)
        ->and($activity['grids'][0]['current'])->toBeTrue()
        ->and($activity['grids'][0]['trading'])->toBeTrue()
        ->and($activity['grids'][0]['levels'][0]['price'])->toBe('49900.0000000000000000')
        ->and($activity['fills'])->toHaveCount(1)
        ->and($activity['cancels'])->toHaveCount(1)
        ->and($activity['summary'])->toMatchArray([
            'fill_count' => 1,
            'cancel_count' => 1,
            'cancel_fill_ratio' => '1.00',
            'grid_revisions' => 1,
            'spacing' => '120.0000000000000000',
            'spacing_type' => 'dynamic',
            'spacing_hysteresis_percent' => '35',
            'cancel_causes' => 'all',
        ])
        ->and($activity['truncated'])->toBe(['fills' => false, 'cancels' => false, 'grids' => false]);
});

it('rounds a non-terminating cancel-to-fill ratio to two decimals', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create();

    foreach ([10, 20, 30] as $minutes) {
        Fill::factory()->create([
            'bot_id' => $bot->id,
            'filled_at' => now()->subMinutes($minutes),
        ]);
    }

    foreach ([5, 15] as $minutes) {
        Order::factory()->create([
            'bot_id' => $bot->id,
            'status' => OrderStatus::Canceled,
            'closed_at' => now()->subMinutes($minutes),
        ]);
    }

    expect($service->build($bot, '1h')['summary']['cancel_fill_ratio'])->toBe('0.67');
});

it('uses strategy configuration for static spacing even when the stored grid says arithmetic', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create([
        'strategy_key' => 'simple-grid',
        'status' => 'paused',
        'last_successful_tick_at' => now()->subMinutes(7),
    ]);
    StrategyConfig::factory()->create([
        'bot_id' => $bot->id,
        'strategy_key' => 'simple-grid',
        'configuration' => ['spacing_type' => 'geometric', 'spacing_value' => '0.004'],
    ]);
    Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Active,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '500',
        'created_at' => now()->subHour(),
        'updated_at' => now(),
    ]);

    $activity = $service->build($bot, '24h');

    expect($activity['summary']['spacing_type'])->toBe('geometric')
        ->and($activity['summary']['spacing'])->toBe('0.004')
        ->and($activity['summary']['spacing_hysteresis_percent'])->toBeNull()
        ->and($activity['grids'][0]['current'])->toBeTrue()
        ->and($activity['grids'][0]['trading'])->toBeFalse()
        ->and(Carbon::parse($activity['grids'][0]['ended_at'])->timestamp)
        ->toBe(now()->subMinutes(7)->timestamp);
});

it('shows null dynamic spacing before a grid exists and defaults hysteresis to 20', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create(['strategy_key' => 'dynamic-grid']);
    StrategyConfig::factory()->create([
        'bot_id' => $bot->id,
        'strategy_key' => 'dynamic-grid',
        'configuration' => ['spacing_type' => 'dynamic', 'spacing_value' => '0'],
    ]);

    $activity = $service->build($bot, '24h');

    expect($activity['summary']['spacing'])->toBeNull()
        ->and($activity['summary']['spacing_type'])->toBe('dynamic')
        ->and($activity['summary']['spacing_hysteresis_percent'])->toBe('20')
        ->and($activity['grids'])->toBeEmpty()
        ->and($activity['summary']['grid_revisions'])->toBe(0);
});

it('keeps the newest markers and reports exact totals when truncated', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    config(['trading.grid_activity.max_markers' => 2]);
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create();

    foreach ([10, 20, 30] as $minutes) {
        Fill::factory()->create([
            'bot_id' => $bot->id,
            'price' => (string) $minutes,
            'filled_at' => now()->subMinutes($minutes),
        ]);
    }

    $activity = $service->build($bot, '1h');

    expect($activity['fills'])->toHaveCount(2)
        ->and($activity['fills'][0]['price'])->toBe('20.0000000000000000')
        ->and($activity['fills'][1]['price'])->toBe('10.0000000000000000')
        ->and($activity['summary']['fill_count'])->toBe(3)
        ->and($activity['truncated']['fills'])->toBeTrue();
});

it('uses half-open window boundaries and clips grid lifetimes', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create();
    $windowStart = now()->subHour();

    Fill::factory()->create(['bot_id' => $bot->id, 'filled_at' => $windowStart]);
    Fill::factory()->create(['bot_id' => $bot->id, 'filled_at' => now()]);

    $old = Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Stopped,
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(2),
    ]);
    $inside = Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Stopped,
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(10),
    ]);
    $active = Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Active,
        'created_at' => now()->subHours(4),
        'updated_at' => now(),
    ]);

    $activity = $service->build($bot, '1h');

    expect($activity['summary']['fill_count'])->toBe(1)
        ->and($activity['fills'][0]['filled_at'])->toBe($windowStart->toIso8601String())
        ->and($activity['summary']['grid_revisions'])->toBe(2)
        ->and($activity['grids'])->toHaveCount(2)
        ->and($activity['grids'][0]['id'])->toBe($active->id)
        ->and($activity['grids'][0]['started_at'])->toBe($windowStart->toIso8601String())
        ->and($activity['grids'][1]['id'])->toBe($inside->id)
        ->and($activity['grids'][1]['started_at'])->toBe(now()->subMinutes(30)->toIso8601String())
        ->and(collect($activity['grids'])->pluck('id'))->not->toContain($old->id);
});

it('computes the median lifetime of stopped grids replaced inside the window', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create();

    Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Stopped,
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(20),
    ]);
    Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Stopped,
        'created_at' => now()->subMinutes(50),
        'updated_at' => now()->subMinutes(10),
    ]);

    $activity = $service->build($bot, '1h');

    expect($activity['summary']['median_lifetime_seconds'])->toBe(1500);
});

it('caps cancels and grids independently while keeping exact totals', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    config(['trading.grid_activity.max_markers' => 2, 'trading.grid_activity.max_grids' => 2]);
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create();

    foreach ([10, 20, 30] as $minutes) {
        Order::factory()->create([
            'bot_id' => $bot->id,
            'status' => OrderStatus::Canceled,
            'closed_at' => now()->subMinutes($minutes),
        ]);
        Grid::factory()->create([
            'bot_id' => $bot->id,
            'status' => GridStatus::Stopped,
            'created_at' => now()->subMinutes($minutes + 5),
            'updated_at' => now()->subMinutes($minutes),
        ]);
    }

    $activity = $service->build($bot, '1h');

    expect($activity['cancels'])->toHaveCount(2)
        ->and($activity['cancels'][0]['closed_at'])->toBe(now()->subMinutes(20)->toIso8601String())
        ->and($activity['summary']['cancel_count'])->toBe(3)
        ->and($activity['truncated']['cancels'])->toBeTrue()
        ->and($activity['grids'])->toHaveCount(2)
        ->and($activity['summary']['grid_revisions'])->toBe(3)
        ->and($activity['truncated']['grids'])->toBeTrue();
});

it('counts can only once at the window boundary and includes an empty grid', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create();
    $windowStart = now()->subHour();

    Order::factory()->create([
        'bot_id' => $bot->id,
        'status' => OrderStatus::Canceled,
        'closed_at' => $windowStart,
    ]);
    Order::factory()->create([
        'bot_id' => $bot->id,
        'status' => OrderStatus::Canceled,
        'closed_at' => now(),
    ]);
    Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Active,
        'created_at' => now()->subMinutes(20),
        'updated_at' => now(),
    ]);

    $activity = $service->build($bot, '1h');

    expect($activity['summary']['cancel_count'])->toBe(1)
        ->and($activity['cancels'][0]['closed_at'])->toBe($windowStart->toIso8601String())
        ->and($activity['grids'][0]['levels'])->toBeEmpty();
});

it('reports a null ratio without fills and a null median without stopped grids', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create();

    Order::factory()->create([
        'bot_id' => $bot->id,
        'status' => OrderStatus::Canceled,
        'closed_at' => now()->subMinutes(5),
    ]);
    Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Active,
        'created_at' => now()->subMinutes(20),
        'updated_at' => now(),
    ]);

    $activity = $service->build($bot, '1h');

    expect($activity['summary']['cancel_fill_ratio'])->toBeNull()
        ->and($activity['summary']['median_lifetime_seconds'])->toBeNull();
});

it('prefers the active grid spacing and does not add revisions on re-arm', function () {
    Carbon::setTestNow('2026-11-28 12:00:00');
    $service = gridActivityService(new FakeExchangeClient);
    $bot = Bot::factory()->create(['strategy_key' => 'dynamic-grid']);
    StrategyConfig::factory()->create([
        'bot_id' => $bot->id,
        'strategy_key' => 'dynamic-grid',
        'configuration' => ['spacing_type' => 'dynamic', 'spacing_value' => '0'],
    ]);

    $active = Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Active,
        'spacing_value' => '120',
        'created_at' => now()->subHours(2),
        'updated_at' => now(),
    ]);
    Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Stopped,
        'spacing_value' => '999',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(5),
    ]);

    $activity = $service->build($bot, '1h');

    expect($activity['summary']['spacing'])->toBe('120.0000000000000000')
        ->and($activity['summary']['grid_revisions'])->toBe(2);

    $active->touch();

    expect($service->build($bot, '1h')['summary']['grid_revisions'])->toBe(2);
});
