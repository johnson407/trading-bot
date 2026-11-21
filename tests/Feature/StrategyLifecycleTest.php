<?php

use App\Domain\Bot\BotTickPipeline;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Order\Enums\OrderStatus;
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

final class LifecycleStrategy implements StrategyInterface
{
    /** @var list<string> */
    public array $canceled = [];

    public function __construct(
        private readonly bool $stop = false,
        private readonly ?string $cancelClientOrderId = null,
    ) {}

    public function key(): string
    {
        return 'lifecycle-test';
    }

    public function initialize(StrategyInput $input): StrategyDecision
    {
        return $this->onTick($input);
    }

    public function onTick(StrategyInput $input): StrategyDecision
    {
        return new StrategyDecision(
            state: $input->state,
            stop: $this->stop,
            cancelClientOrderIds: $this->cancelClientOrderId === null ? [] : [$this->cancelClientOrderId],
        );
    }

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
    {
        return StrategyDecision::idle($input->state);
    }

    public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
    {
        $this->canceled[] = $cancel->clientOrderId;

        return StrategyDecision::idle($input->state->merge(['last_canceled' => $cancel->clientOrderId]));
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

function lifecycleBot(LifecycleStrategy $strategy, ?array $state = ['version' => 1, 'data' => []], $lastSuccessful = null): array
{
    $bot = Bot::factory()->running()->create([
        'symbol' => 'BTC/USDT',
        'strategy_key' => $strategy->key(),
        'quote_inventory' => '1000',
        'fee_rate' => '0',
        'last_successful_tick_at' => $lastSuccessful,
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => $strategy->key(),
        'configuration' => [],
        'state' => $state,
    ]);

    app(StrategyRegistry::class)->register($strategy);

    $fake = new FakeExchangeClient(price: '95');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
        new PaperExchangeClient($fake),
        $fake,
    ));

    return [$bot, $fake];
}

it('calls onCancel for a client order id the strategy cancels', function () {
    $strategy = new LifecycleStrategy(cancelClientOrderId: 'order-x');
    [$bot] = lifecycleBot($strategy);

    $order = Order::factory()->for($bot)->create([
        'client_order_id' => 'order-x',
        'status' => OrderStatus::Open,
    ]);

    app(BotTickPipeline::class)->tick($bot);

    expect($strategy->canceled)->toBe(['order-x'])
        ->and($order->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($bot->fresh()->status)->toBe(BotStatus::Running);
});

it('honours a strategy stop decision by pausing and cancelling open orders', function () {
    $strategy = new LifecycleStrategy(stop: true);
    [$bot] = lifecycleBot($strategy);

    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open, 'price' => '50']);

    app(BotTickPipeline::class)->tick($bot);

    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($bot->fresh()->last_error)->toContain('strategy requested')
        ->and($order->fresh()->status)->toBe(OrderStatus::Canceled);
});

it('pauses instead of reinitialising state for a bot that already ran', function () {
    $strategy = new LifecycleStrategy;
    [$bot] = lifecycleBot($strategy, state: null, lastSuccessful: now()->subMinute());

    $result = app(BotTickPipeline::class)->tick($bot);

    expect($result->successful())->toBeFalse()
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($bot->fresh()->last_error)->toContain('persisted strategy state is missing')
        ->and(Order::query()->count())->toBe(0);
});
