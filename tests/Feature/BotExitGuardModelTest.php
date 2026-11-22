<?php

use App\Http\Resources\BotSummaryResource;
use App\Models\Bot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts and exposes protective exit rules', function () {
    $bot = Bot::factory()->create([
        'stop_loss_percent' => '5',
        'take_profit_percent' => '12.5',
        'range_low' => '90000',
        'range_high' => '110000',
    ]);

    expect($bot->stop_loss_percent)->toBe('5.0000')
        ->and($bot->take_profit_percent)->toBe('12.5000')
        ->and($bot->range_low)->toBe('90000.00000000')
        ->and($bot->range_high)->toBe('110000.00000000');

    $resource = BotSummaryResource::make($bot)->resolve();

    expect($resource['stop_loss_percent'])->toBe('5.0000')
        ->and($resource['take_profit_percent'])->toBe('12.5000')
        ->and($resource['range_low'])->toBe('90000.00000000')
        ->and($resource['range_high'])->toBe('110000.00000000');
});

it('defaults every protective exit rule to disabled', function () {
    $bot = Bot::factory()->create();

    expect($bot->stop_loss_percent)->toBeNull()
        ->and($bot->take_profit_percent)->toBeNull()
        ->and($bot->range_low)->toBeNull()
        ->and($bot->range_high)->toBeNull();

    $resource = BotSummaryResource::make($bot)->resolve();

    expect($resource['stop_loss_percent'])->toBeNull()
        ->and($resource['take_profit_percent'])->toBeNull()
        ->and($resource['range_low'])->toBeNull()
        ->and($resource['range_high'])->toBeNull();
});

it('provides configured and disabled factory states', function () {
    $configured = Bot::factory()->withExitRules()->create();
    $disabled = Bot::factory()->withoutExitRules()->create();

    expect($configured->stop_loss_percent)->toBe('4.0000')
        ->and($configured->take_profit_percent)->toBe('9.0000')
        ->and($configured->range_low)->toBe('80000.00000000')
        ->and($configured->range_high)->toBe('120000.00000000')
        ->and($disabled->stop_loss_percent)->toBeNull()
        ->and($disabled->take_profit_percent)->toBeNull()
        ->and($disabled->range_low)->toBeNull()
        ->and($disabled->range_high)->toBeNull();
});
