<?php

use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Grid\Enums\GridStatus;
use App\Domain\Grid\GridGenerator;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\FillProcessor;
use App\Domain\Order\OrderPlacementService;
use App\Domain\Order\OrderStateMachine;
use App\Domain\Order\ValueObjects\FillData;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Grid;
use App\Models\GridLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('completes one buy-then-sell grid cycle with exact inventory and P&L', function () {
    $bot = Bot::factory()->create([
        'base_inventory' => '0',
        'quote_inventory' => '1000',
        'average_entry_price' => '0',
        'realized_pnl' => '0',
        'fees_paid' => '0',
    ]);

    $grid = Grid::factory()->for($bot)->create([
        'center_price' => '100',
        'spacing_type' => GridSpacingType::Arithmetic,
        'level_count' => 2,
        'spacing_value' => '10',
        'status' => GridStatus::Active,
    ]);

    $levels = app(GridGenerator::class)->generate('100', GridSpacingType::Arithmetic, '10', 1);

    $buyLevel = GridLevel::create([
        'grid_id' => $grid->id,
        'sequence' => $levels[0]->sequence,
        'price' => $levels[0]->price->toString(),
        'side' => $levels[0]->side,
        'state' => 'idle',
    ]);

    $sellLevel = GridLevel::create([
        'grid_id' => $grid->id,
        'sequence' => $levels[1]->sequence,
        'price' => $levels[1]->price->toString(),
        'side' => $levels[1]->side,
        'state' => 'idle',
    ]);

    expect($buyLevel->price)->toBe('90.0000000000000000')
        ->and($sellLevel->price)->toBe('110.0000000000000000');

    $placement = app(OrderPlacementService::class);
    $stateMachine = app(OrderStateMachine::class);

    $buyOrder = $placement->request($bot, $grid, $buyLevel, OrderSide::Buy, '90', '1');
    $stateMachine->transition($buyOrder, OrderStatus::Open);

    $buyFill = app(FillProcessor::class)->process(
        $bot,
        $buyOrder,
        FillData::make('paper', 'paper-fill-1', '90', '1', '0'),
    );

    expect($buyFill->duplicate)->toBeFalse()
        ->and($buyFill->opposite)->not->toBeNull()
        ->and($buyFill->opposite->side)->toBe(OrderSide::Sell)
        ->and($buyFill->opposite->gridLevelSequence)->toBe($sellLevel->sequence)
        ->and($buyFill->opposite->price->toString())->toBe('110');

    $sellOrder = $placement->request(
        $bot,
        $grid,
        $sellLevel,
        $buyFill->opposite->side,
        $buyFill->opposite->price,
        $buyFill->opposite->quantity,
    );
    $stateMachine->transition($sellOrder, OrderStatus::Open);

    $sellFill = app(FillProcessor::class)->process(
        $bot,
        $sellOrder,
        FillData::make('paper', 'paper-fill-2', '110', '1', '0'),
    );

    $bot->refresh();

    expect($sellFill->duplicate)->toBeFalse()
        ->and($sellFill->fill->quantity)->toBe('1.0000000000000000')
        ->and(Decimal::of($bot->base_inventory)->isZero())->toBeTrue()
        ->and(Decimal::of($bot->quote_inventory)->toString())->toBe('1020')
        ->and(Decimal::of($bot->realized_pnl)->toString())->toBe('20')
        ->and($bot->fills()->count())->toBe(2)
        ->and($bot->inventoryLedgerEntries()->count())->toBe(2)
        ->and($buyLevel->fresh()->state)->toBe(\App\Domain\Grid\Enums\GridLevelState::Filled)
        ->and($sellLevel->fresh()->state)->toBe(\App\Domain\Grid\Enums\GridLevelState::Filled);
});
