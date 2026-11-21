<?php

use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use App\Models\RiskLimit;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('relates bots to their owner, strategy, grid, orders and fills', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $config = StrategyConfig::factory()->for($bot)->create();
    $grid = Grid::factory()->for($bot)->create();
    $level = GridLevel::factory()->for($grid)->create();
    $order = Order::factory()->for($bot)->create([
        'grid_id' => $grid->id,
        'grid_level_id' => $level->id,
    ]);
    $fill = Fill::factory()->for($bot)->for($order)->create();

    expect($bot->user->is($user))->toBeTrue()
        ->and($bot->strategyConfig->is($config))->toBeTrue()
        ->and($bot->grids->first()->is($grid))->toBeTrue()
        ->and($grid->levels->first()->is($level))->toBeTrue()
        ->and($bot->orders->first()->is($order))->toBeTrue()
        ->and($bot->fills->first()->is($fill))->toBeTrue()
        ->and($order->fills->first()->is($fill))->toBeTrue();
});

it('encrypts and hides exchange credentials', function () {
    $credential = ExchangeCredential::factory()->create([
        'api_key' => 'plain-api-key',
        'api_secret' => 'plain-api-secret',
    ]);

    expect($credential->api_key)->toBe('plain-api-key')
        ->and($credential->toArray())->not->toHaveKey('api_secret')
        ->and($credential->maskedKey())->toBe('plai****-key');

    $raw = DB::table('exchange_credentials')->where('id', $credential->id)->first();

    expect($raw->api_key)->not->toBe('plain-api-key')
        ->and($raw->api_secret)->not->toBe('plain-api-secret');
});

it('casts bot money, modes and statuses to domain values', function () {
    $bot = Bot::factory()->create([
        'mode' => 'paper',
        'status' => 'running',
        'capital_allocation' => '1234.5000000000000000',
        'settings' => ['key' => 'value'],
    ]);

    expect($bot->mode)->toBe(\App\Domain\Bot\Enums\BotMode::Paper)
        ->and($bot->status)->toBe(\App\Domain\Bot\Enums\BotStatus::Running)
        ->and($bot->capital_allocation)->toBe('1234.5000000000000000')
        ->and($bot->settings)->toBe(['key' => 'value']);
});

it('attaches user and bot risk limits and activity logs', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $limit = RiskLimit::factory()->forBot($bot)->create();
    $log = ActivityLog::factory()->create(['user_id' => $user->id, 'bot_id' => $bot->id]);

    expect($bot->riskLimit->is($limit))->toBeTrue()
        ->and($user->riskLimits->first()->is($limit))->toBeTrue()
        ->and($bot->activityLogs->first()->is($log))->toBeTrue();
});
