<?php

use App\Domain\Bot\BotTickPipeline;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Models\Bot;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

class RecentPriceSpyStrategy implements StrategyInterface
{
    /** @var list<list<string>> */
    public static array $seen = [];

    public function key(): string
    {
        return 'recent-spy';
    }

    public function initialize(StrategyInput $input): StrategyDecision
    {
        return new StrategyDecision(state: $input->state->merge(['initialized' => true]));
    }

    public function onTick(StrategyInput $input): StrategyDecision
    {
        self::$seen[] = array_map(fn ($price) => (string) $price, $input->market->recentPrices);

        return StrategyDecision::idle($input->state);
    }

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
    {
        return StrategyDecision::idle($input->state);
    }

    public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
    {
        return StrategyDecision::idle($input->state);
    }

    public function shouldStop(StrategyInput $input): bool
    {
        return false;
    }

    /** @return list<GridLevelPlan> */
    public function getGridLevels(StrategyInput $input): array
    {
        return [];
    }
}

function runtimeBot(string $price, array $config, string $strategyKey = 'trailing-grid'): array
{
    $bot = Bot::factory()->running()->create([
        'symbol' => 'BTC/USDT',
        'strategy_key' => $strategyKey,
        'quote_inventory' => '1000',
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'fee_rate' => '0',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => $strategyKey,
        'configuration' => $config,
        'state' => null,
    ]);

    $fake = new FakeExchangeClient(price: $price);
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
        new PaperExchangeClient($fake),
        $fake,
    ));

    return [$bot, $fake];
}

it('feeds a rolling window of recent prices to the strategy', function () {
    RecentPriceSpyStrategy::$seen = [];
    app(StrategyRegistry::class)->register(new RecentPriceSpyStrategy);

    [$bot, $fake] = runtimeBot('100', [], 'recent-spy');

    $pipeline = app(BotTickPipeline::class);
    $pipeline->tick($bot);

    $fake->price = '105';
    $pipeline->tick($bot->fresh());

    expect($bot->fresh()->settings['recent_prices'])->toBe(['100', '105'])
        ->and(RecentPriceSpyStrategy::$seen)->toHaveCount(2)
        ->and(RecentPriceSpyStrategy::$seen[1])->toBe(['100', '105']);
});

it('places orders on every level after a trailing grid recenter', function () {
    [$bot, $fake] = runtimeBot('95', [
        'center_price' => '100',
        'levels_per_side' => 1,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '10',
        'order_quantity' => '1',
        'trailing_distance' => '5',
    ]);

    $pipeline = app(BotTickPipeline::class);
    $pipeline->tick($bot);

    expect(Order::query()->where('status', 'open')->count())->toBe(2);

    $fake->price = '120';
    $pipeline->tick($bot->fresh());

    $open = Order::query()->where('status', 'open')->orderBy('price')->get();

    expect($open)->toHaveCount(2)
        ->and($open->pluck('price')->all())->toBe(['110.0000000000000000', '130.0000000000000000'])
        ->and($open->first()->side)->toBe(OrderSide::Buy)
        ->and($open->last()->side)->toBe(OrderSide::Sell);
});
