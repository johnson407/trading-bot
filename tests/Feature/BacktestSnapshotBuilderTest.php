<?php

use App\Domain\Backtest\Enums\Timeframe;
use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Backtest\BacktestSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies only the supported sweep overrides to the snapshot', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
    ]);
    $config = StrategyConfig::factory()->for($bot)->create();

    $snapshot = app(BacktestSnapshotBuilder::class)->build(
        $bot,
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-02T00:00:00Z'),
        [
            'center_price' => '51000',
            'spacing_value' => '250',
            'level_count' => 9,
            'levels_per_side' => 9,
            'order_quantity' => '999',
            'unknown' => 'ignored',
        ],
    );

    $configuration = $snapshot['strategy']['configuration'];

    expect($configuration['center_price'])->toBe('51000')
        ->and($configuration['spacing_value'])->toBe('250')
        ->and($configuration['level_count'])->toBe(9)
        ->and($configuration['levels_per_side'])->toBe(9)
        ->and($configuration['order_quantity'])->toBe($config->configuration['order_quantity'])
        ->and($configuration['spacing_type'])->toBe($config->configuration['spacing_type'])
        ->and($configuration)->not->toHaveKey('unknown')
        ->and($snapshot['bot']['capital_allocation'])->toBe('1000')
        ->and($snapshot['bot']['fee_rate'])->toBe('0.001');
});
