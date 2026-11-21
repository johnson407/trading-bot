<?php

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Grid\GridGenerator;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\FillProcessor;
use App\Domain\Order\ValueObjects\FillData;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fillFixture(OrderSide $side = OrderSide::Buy): array
{
    $bot = Bot::factory()->create($side === OrderSide::Sell
        ? ['base_inventory' => '1', 'average_entry_price' => '100']
        : []);
    $grid = Grid::factory()->for($bot)->create();
    $levels = app(GridGenerator::class)->generate('100', $grid->spacing_type, '10', 2);

    foreach ($levels as $plan) {
        GridLevel::create([
            'grid_id' => $grid->id,
            'sequence' => $plan->sequence,
            'price' => $plan->price->toString(),
            'side' => $plan->side,
            'state' => 'open',
        ]);
    }

    $level = GridLevel::where('grid_id', $grid->id)->where('sequence', 1)->firstOrFail();
    $order = Order::factory()->for($bot)->create([
        'grid_id' => $grid->id,
        'grid_level_id' => $level->id,
        'side' => $side,
        'price' => '80',
        'quantity' => '0.5',
        'filled_quantity' => '0',
        'status' => OrderStatus::Open,
    ]);

    return [$bot, $grid, $level, $order];
}

it('processes a buy fill and requests the opposite sell order', function () {
    [$bot, $grid, $level, $order] = fillFixture(OrderSide::Buy);

    $result = app(FillProcessor::class)->process($bot, $order, FillData::make('binance', 'fill-1', '80', '0.2'));

    expect($result->duplicate)->toBeFalse()
        ->and($result->order->status)->toBe(OrderStatus::PartiallyFilled)
        ->and($result->order->filled_quantity)->toBe('0.2000000000000000')
        ->and($result->opposite?->side)->toBe(OrderSide::Sell)
        ->and($result->opposite?->price->toString())->toBe('90')
        ->and($result->opposite?->quantity->toString())->toBe('0.2')
        ->and(Fill::count())->toBe(1);
});

it('processes a sell fill and requests the opposite buy order', function () {
    [$bot, $grid, $level, $order] = fillFixture(OrderSide::Sell);
    $order->update(['grid_level_id' => GridLevel::where('grid_id', $grid->id)->where('sequence', 3)->value('id'), 'price' => '110']);

    $result = app(FillProcessor::class)->process($bot, $order, FillData::make('binance', 'fill-2', '110', '0.5'));

    expect($result->order->status)->toBe(OrderStatus::Filled)
        ->and($result->opposite?->side)->toBe(OrderSide::Buy)
        ->and($result->opposite?->price->toString())->toBe('90');
});

it('ignores duplicate exchange fills', function () {
    [$bot, $grid, $level, $order] = fillFixture();

    $first = app(FillProcessor::class)->process($bot, $order, FillData::make('binance', 'same-fill', '80', '0.2'));
    $second = app(FillProcessor::class)->process($bot, $order, FillData::make('binance', 'same-fill', '80', '0.2'));

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and(Fill::count())->toBe(1)
        ->and($order->fresh()->filled_quantity)->toBe('0.2000000000000000');
});

it('rolls back the fill when later work fails', function () {
    [$bot, $grid, $level, $order] = fillFixture();

    $recorder = Mockery::mock(ActivityRecorder::class);
    $recorder->shouldReceive('record')->andThrow(new RuntimeException('boom'));
    app()->instance(ActivityRecorder::class, $recorder);

    expect(fn () => app(FillProcessor::class)->process($bot, $order, FillData::make('binance', 'rollback', '80', '0.2')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Fill::count())->toBe(0)
        ->and($order->fresh()->filled_quantity)->toBe('0.0000000000000000');
});

it('pauses the bot and rolls back a fill that would create negative inventory', function () {
    $bot = Bot::factory()->create(['base_inventory' => '0']);
    $grid = Grid::factory()->for($bot)->create();
    $level = GridLevel::factory()->for($grid)->create(['sequence' => 1, 'price' => '100', 'side' => OrderSide::Sell]);
    $order = Order::factory()->for($bot)->create([
        'grid_id' => $grid->id,
        'grid_level_id' => $level->id,
        'side' => OrderSide::Sell,
        'price' => '100',
        'quantity' => '1',
        'status' => OrderStatus::Open,
    ]);

    expect(fn () => app(FillProcessor::class)->process($bot, $order, FillData::make('binance', 'short', '100', '1')))
        ->toThrow(\App\Domain\Portfolio\Exceptions\NegativeInventoryException::class);

    expect($bot->fresh()->status)->toBe(\App\Domain\Bot\Enums\BotStatus::Paused)
        ->and(Fill::count())->toBe(0)
        ->and($order->fresh()->filled_quantity)->toBe('0.0000000000000000');
});
