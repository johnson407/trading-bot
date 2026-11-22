<?php

use App\Models\BacktestRun;
use App\Models\BacktestSweep;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts sweep parameters and dates and relates the owner, bot, and runs', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $sweep = BacktestSweep::factory()->for($user)->for($bot)->create([
        'parameters' => [
            'center_prices' => ['50000', '51000'],
            'spacing_values' => ['100'],
            'level_counts' => [4, 5],
        ],
        'total_runs' => 4,
    ]);
    $run = BacktestRun::factory()->for($user)->forBot($bot)->create(['sweep_id' => $sweep->id]);

    expect($sweep->parameters)->toBe([
        'center_prices' => ['50000', '51000'],
        'spacing_values' => ['100'],
        'level_counts' => [4, 5],
    ])
        ->and($sweep->total_runs)->toBe(4)
        ->and($sweep->start_at)->toBeInstanceOf(Carbon::class)
        ->and($sweep->end_at)->toBeInstanceOf(Carbon::class)
        ->and($sweep->user->is($user))->toBeTrue()
        ->and($sweep->bot->is($bot))->toBeTrue()
        ->and($sweep->runs->first()->is($run))->toBeTrue()
        ->and($run->sweep->is($sweep))->toBeTrue()
        ->and($user->backtestSweeps->first()->is($sweep))->toBeTrue();
});

it('keeps sweep runs when the sweep or bot is deleted', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $sweep = BacktestSweep::factory()->for($user)->for($bot)->create();
    $run = BacktestRun::factory()->for($user)->forBot($bot)->create(['sweep_id' => $sweep->id]);

    $sweep->delete();

    expect($run->refresh()->sweep_id)->toBeNull()
        ->and($run->sweep)->toBeNull()
        ->and(BacktestRun::query()->whereKey($run->id)->exists())->toBeTrue();
});

it('keeps sweep runs and the sweep when the bot is deleted', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $sweep = BacktestSweep::factory()->for($user)->for($bot)->create();
    $run = BacktestRun::factory()->for($user)->forBot($bot)->create(['sweep_id' => $sweep->id]);

    $bot->delete();

    expect($sweep->refresh()->bot_id)->toBeNull()
        ->and($run->refresh()->bot_id)->toBeNull()
        ->and($run->sweep_id)->toBe($sweep->id)
        ->and(BacktestSweep::query()->whereKey($sweep->id)->exists())->toBeTrue()
        ->and(BacktestRun::query()->whereKey($run->id)->exists())->toBeTrue();
});

it('has a valid default factory sweep', function () {
    $sweep = BacktestSweep::factory()->make();
    $parameters = $sweep->parameters;
    $combinations = count($parameters['center_prices'])
        * count($parameters['spacing_values'])
        * count($parameters['level_counts']);

    expect($sweep->total_runs)->toBe($combinations)
        ->and($sweep->total_runs)->toBeGreaterThanOrEqual(2)
        ->and($sweep->total_runs)->toBeLessThanOrEqual(BacktestSweep::maximumRuns());
});
