<?php

use App\Domain\Backtest\Engine\InMemoryPortfolio;
use App\Domain\Backtest\Engine\SimulatedOrderBook;
use App\Domain\Backtest\ValueObjects\SimulatedFill;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Portfolio\InventoryLedger;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Order;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('matches the inventory ledger average-cost arithmetic', function () {
    $bot = Bot::factory()->create([
        'symbol' => 'BTC/USDT',
        'base_inventory' => '0',
        'quote_inventory' => '1000',
        'average_entry_price' => '0',
        'realized_pnl' => '0',
        'fees_paid' => '0',
    ]);
    $order = Order::factory()->for($bot)->create(['side' => 'buy']);
    $ledger = app(InventoryLedger::class);
    $portfolio = new InMemoryPortfolio('1000');

    $fills = [
        ['buy', '90', '1', '0.09'],
        ['sell', '110', '1', '0.11'],
    ];

    foreach ($fills as $index => [$side, $price, $quantity, $fee]) {
        $fill = Fill::query()->create([
            'bot_id' => $bot->id,
            'order_id' => $order->id,
            'exchange' => 'paper',
            'exchange_fill_id' => 'parity-'.$index,
            'side' => $side,
            'price' => $price,
            'quantity' => $quantity,
            'fee' => $fee,
            'fee_currency' => 'USDT',
            'filled_at' => now('UTC'),
        ]);

        $ledger->apply($bot, $fill);
        $portfolio->apply(new SimulatedFill(
            sequence: $index + 1,
            side: OrderSide::from($side),
            price: Decimal::of($price),
            quantity: Decimal::of($quantity),
            fee: Decimal::of($fee),
            feeCurrency: 'USDT',
            filledAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        ));
    }

    $bot->refresh();

    expect($portfolio->base->toString())->toBe(Decimal::of($bot->base_inventory)->toString())
        ->and($portfolio->quote->toString())->toBe(Decimal::of($bot->quote_inventory)->toString())
        ->and($portfolio->averageEntry->toString())->toBe(Decimal::of($bot->average_entry_price)->toString())
        ->and($portfolio->realized->toString())->toBe(Decimal::of($bot->realized_pnl)->toString())
        ->and($portfolio->fees->toString())->toBe(Decimal::of($bot->fees_paid)->toString());
});

it('matches paper fill prices, quantities, and fees', function () {
    $bot = Bot::factory()->create(['symbol' => 'BTC/USDT']);
    $buy = Order::factory()->for($bot)->create([
        'client_order_id' => 'paper-buy',
        'side' => 'buy',
        'price' => '90',
        'quantity' => '1',
        'filled_quantity' => '0',
        'status' => 'open',
    ]);
    $secondBuy = Order::factory()->for($bot)->create([
        'client_order_id' => 'paper-buy-two',
        'side' => 'buy',
        'price' => '95',
        'quantity' => '2',
        'filled_quantity' => '0',
        'status' => 'open',
    ]);

    $market = MarketData::make('BTC/USDT', '85');
    $paper = app(PaperExchangeClient::class)->qualifyingFills($market, [$buy, $secondBuy], '0.001');

    $book = new SimulatedOrderBook('USDT');
    $book->place(OrderSide::Buy, '90', '1', 1, 0);
    $book->place(OrderSide::Buy, '95', '2', 2, 0);
    $simulated = $book->qualifyingOrders(Decimal::of('85'), 1);

    expect($simulated)->toHaveCount(2)
        ->and($paper)->toHaveCount(2)
        ->and($simulated[0]->price->toString())->toBe($paper[0]->price->toString())
        ->and($simulated[0]->quantity->toString())->toBe($paper[0]->quantity->toString())
        ->and($simulated[1]->price->toString())->toBe($paper[1]->price->toString())
        ->and($simulated[1]->quantity->toString())->toBe($paper[1]->quantity->toString());

    $firstFill = $book->fill($simulated[0], new DateTimeImmutable('2026-01-01T00:00:00Z'), Decimal::of('0.001'));
    $secondFill = $book->fill($simulated[1], new DateTimeImmutable('2026-01-01T00:00:00Z'), Decimal::of('0.001'));

    expect($firstFill->fee->toString())->toBe($paper[0]->fee->toString())
        ->and($secondFill->fee->toString())->toBe($paper[1]->fee->toString());
});
