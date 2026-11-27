<?php

use App\Http\Resources\BotSummaryResource;
use App\Models\BacktestRun;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('links a bot to its source backtest run', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->create();
    $bot = Bot::factory()->for($user)->create([
        'source_backtest_run_id' => $run->id,
    ]);

    expect($bot->sourceBacktest->is($run))->toBeTrue()
        ->and($run->derivedBots()->pluck('id')->all())->toBe([$bot->id]);
});

it('provides source backtest factory states', function () {
    $run = BacktestRun::factory()->completedSource()->create();

    expect($run->metrics['return_percent'])->toBe('5')
        ->and($run->config_snapshot['exit']['stop_loss_percent'])->toBe('4');

    $bot = Bot::factory()->for($run->user)->fromBacktest($run)->create();

    expect($bot->source_backtest_run_id)->toBe($run->id);

    $deletedSourceBot = Bot::factory()->for($run->user)->withDeletedSource()->create();

    expect($deletedSourceBot->source_backtest_run_id)->toBeNull();
});

it('clears the source link when the source run is deleted', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->create();
    $bot = Bot::factory()->for($user)->create([
        'name' => 'Source linked bot',
        'capital_allocation' => '2500',
        'source_backtest_run_id' => $run->id,
    ]);

    $run->delete();

    $bot->refresh();

    expect($bot->source_backtest_run_id)->toBeNull()
        ->and($bot->sourceBacktest)->toBeNull()
        ->and($bot->name)->toBe('Source linked bot')
        ->and($bot->capital_allocation)->toBe('2500.0000000000000000')
        ->and(Bot::find($bot->id))->not->toBeNull();
});

it('exposes the source run id in the bot summary resource', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->create();
    $bot = Bot::factory()->for($user)->create([
        'source_backtest_run_id' => $run->id,
    ]);

    $resolved = (new BotSummaryResource($bot))->resolve(Request::create('/'));

    expect($resolved['source_backtest_run_id'])->toBe($run->id);
});

it('deletes source-linked bots and runs when the user is deleted', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->create();
    $bot = Bot::factory()->for($user)->create([
        'source_backtest_run_id' => $run->id,
    ]);

    $user->delete();

    expect(Bot::withTrashed()->find($bot->id))->toBeNull()
        ->and(BacktestRun::find($run->id))->toBeNull();
});
