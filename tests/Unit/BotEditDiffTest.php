<?php

use App\Domain\Bot\BotEditDiff;
use App\Models\Bot;
use App\Models\StrategyConfig;

function editDiffBot(array $configuration = [], array $attributes = []): Bot
{
    $bot = new Bot(array_merge([
        'name' => 'Grid',
        'capital_allocation' => '1000.0000000000000000',
        'fee_rate' => '0.00100000',
        'strategy_key' => 'simple-grid',
    ], $attributes));

    $bot->setRelation('strategyConfig', new StrategyConfig([
        'configuration' => array_merge([
            'center_price' => '50000.0000000000000000',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100.0000000000000000',
            'order_quantity' => '0.0010000000000000',
        ], $configuration),
    ]));

    return $bot;
}

it('returns no changes for equivalent values', function () {
    $bot = editDiffBot();

    expect(BotEditDiff::between($bot, [
        'name' => 'Grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ],
    ]))->toBe([]);
});

it('reports changed bot and strategy fields with labels and types', function () {
    $bot = editDiffBot();

    $diff = BotEditDiff::between($bot, [
        'name' => 'Renamed grid',
        'capital_allocation' => '1500',
        'fee_rate' => '0.002',
        'configuration' => [
            'center_price' => '51000',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ],
    ]);

    expect($diff)->toHaveCount(4)
        ->and($diff[0])->toMatchArray([
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'from' => 'Grid',
            'to' => 'Renamed grid',
        ])
        ->and($diff[1])->toMatchArray([
            'key' => 'capital_allocation',
            'label' => 'Capital allocation',
            'type' => 'money',
            'from' => '1000',
            'to' => '1500',
        ])
        ->and($diff[2])->toMatchArray([
            'key' => 'fee_rate',
            'label' => 'Fee rate',
            'type' => 'rate',
            'from' => '0.001',
            'to' => '0.002',
        ])
        ->and($diff[3])->toMatchArray([
            'key' => 'strategy.center_price',
            'label' => 'Center price',
            'type' => 'money',
            'from' => '50000',
            'to' => '51000',
        ]);
});

it('reports nested strategy fields including added optional values', function () {
    $bot = editDiffBot();

    $diff = BotEditDiff::between($bot, [
        'name' => 'Grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 4,
            'spacing_type' => 'geometric',
            'spacing_value' => '100',
            'order_quantity' => '0.002',
            'trailing_distance' => '200',
        ],
    ]);

    expect($diff)->toHaveCount(4)
        ->and(array_column($diff, 'key'))->toBe([
            'strategy.levels_per_side',
            'strategy.spacing_type',
            'strategy.order_quantity',
            'strategy.trailing_distance',
        ])
        ->and($diff[0]['from'])->toBe('2')
        ->and($diff[0]['to'])->toBe('4')
        ->and($diff[3]['from'])->toBeNull()
        ->and($diff[3]['to'])->toBe('200');
});

it('normalizes decimal strings before comparing', function () {
    $bot = editDiffBot([
        'center_price' => '50000.0000000000000000',
        'order_quantity' => '0.0010000000000000',
    ]);

    expect(BotEditDiff::between($bot, [
        'name' => 'Grid',
        'capital_allocation' => '1000.0000',
        'fee_rate' => '0.001000',
        'configuration' => [
            'center_price' => '50000.00',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100.0',
            'order_quantity' => '0.001',
        ],
    ]))->toBe([]);
});

it('ignores unknown and engine-owned fields', function () {
    $bot = editDiffBot([
        'legacy_key' => 'old',
    ]);

    expect(BotEditDiff::between($bot, [
        'name' => 'Grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
            'unknown_key' => 'new',
        ],
        'settings' => ['mark_price' => '1'],
        'status' => 'paused',
    ]))->toBe([]);
});

it('reports a known strategy field that the proposed configuration removes', function () {
    $bot = editDiffBot([
        'trailing_distance' => '200',
    ]);

    $diff = BotEditDiff::between($bot, [
        'name' => 'Grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ],
    ]);

    expect($diff)->toHaveCount(1)
        ->and($diff[0])->toMatchArray([
            'key' => 'strategy.trailing_distance',
            'from' => '200',
            'to' => null,
        ]);
});

it('reports a known strategy field that the proposed configuration adds', function () {
    $bot = editDiffBot();

    $diff = BotEditDiff::between($bot, [
        'name' => 'Grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
            'max_increases' => 3,
        ],
    ]);

    expect($diff)->toHaveCount(1)
        ->and($diff[0])->toMatchArray([
            'key' => 'strategy.max_increases',
            'type' => 'integer',
            'from' => null,
            'to' => '3',
        ]);
});

it('reports dynamic spacing hysteresis changes', function () {
    $bot = editDiffBot([
        'spacing_hysteresis_percent' => '20',
    ]);

    $diff = BotEditDiff::between($bot, [
        'name' => 'Grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
            'spacing_hysteresis_percent' => '30',
        ],
    ]);

    expect($diff)->toHaveCount(1)
        ->and($diff[0])->toMatchArray([
            'key' => 'strategy.spacing_hysteresis_percent',
            'label' => 'Spacing hysteresis',
            'type' => 'rate',
            'from' => '20',
            'to' => '30',
        ]);
});
