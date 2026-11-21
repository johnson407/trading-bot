<?php

use App\Domain\Bot\BotCapacityHarness;
use App\Domain\Bot\BotTickPipeline;
use App\Models\Bot;
use App\Models\RiskLimit;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('ticks ten bots inside the one-second budget without drift', function () {
    $fake = new FakeExchangeClient(price: '100');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
        new PaperExchangeClient($fake),
        $fake,
    ));

    $bots = collect(range(1, 10))->map(function () {
        $bot = Bot::factory()->running()->create([
            'quote_inventory' => '1000',
            'fee_rate' => '0',
        ]);

        StrategyConfig::factory()->for($bot)->create([
            'configuration' => [
                'center_price' => '100',
                'levels_per_side' => 1,
                'spacing_type' => 'arithmetic',
                'spacing_value' => '10',
                'order_quantity' => '1',
            ],
        ]);

        RiskLimit::factory()->forBot($bot)->create([
            'max_position_notional' => '10000',
            'max_open_orders' => 20,
            'daily_loss_limit' => '1000',
        ]);

        return $bot;
    });

    $report = app(BotCapacityHarness::class)->run($bots, rounds: 3);

    expect($report->bots)->toBe(10)
        ->and($report->rounds)->toBe(3)
        ->and($report->ticks)->toBe(30)
        ->and($report->errors)->toBe(0)
        ->and($report->maxRoundMs)->toBeLessThanOrEqual(1000.0)
        ->and($report->withinBudget)->toBeTrue();
});
