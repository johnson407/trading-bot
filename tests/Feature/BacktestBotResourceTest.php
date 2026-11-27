<?php

use App\Domain\Backtest\Enums\BacktestStatus;
use App\Http\Resources\BacktestRunResource;
use App\Http\Resources\BacktestSweepRunResource;
use App\Models\BacktestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/**
 * @param  class-string<BacktestRunResource|BacktestSweepRunResource>  $resource
 * @return array<string, mixed>
 */
function backtestResourceData(BacktestRun $run, ?User $user, string $resource = BacktestRunResource::class): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return (new $resource($run))->toArray($request);
}

function completedSourceRun(User $user, ?array $snapshot = null): BacktestRun
{
    $factory = BacktestRun::factory()->for($user)->completedSource();

    return $snapshot === null
        ? $factory->create()
        : $factory->create(['config_snapshot' => $snapshot]);
}

it('allows the owner of a completed usable run to create a bot', function () {
    $user = User::factory()->create();
    $run = completedSourceRun($user);

    expect(backtestResourceData($run, $user)['can_create_bot'])->toBeTrue()
        ->and(backtestResourceData($run, $user, BacktestSweepRunResource::class)['can_create_bot'])->toBeTrue();
});

it('rejects pending, failed, and another user runs', function () {
    $user = User::factory()->create();
    $pending = BacktestRun::factory()->for($user)->create();
    $failed = completedSourceRun($user);
    $failed->forceFill(['status' => BacktestStatus::Failed])->save();
    $otherUserRun = completedSourceRun(User::factory()->create());

    expect(backtestResourceData($pending, $user)['can_create_bot'])->toBeFalse()
        ->and(backtestResourceData($failed, $user)['can_create_bot'])->toBeFalse()
        ->and(backtestResourceData($otherUserRun, $user)['can_create_bot'])->toBeFalse()
        ->and(backtestResourceData($otherUserRun, $otherUserRun->user)['can_create_bot'])->toBeTrue();
});

it('rejects guest requests', function () {
    $run = completedSourceRun(User::factory()->create());

    expect(backtestResourceData($run, null)['can_create_bot'])->toBeFalse();
});

it('rejects snapshots that cannot be prefilled', function (callable $mutate) {
    $user = User::factory()->create();
    $base = completedSourceRun($user);
    $snapshot = $base->config_snapshot;
    $mutate($snapshot);
    $run = completedSourceRun($user, $snapshot);

    expect(backtestResourceData($run, $user)['can_create_bot'])->toBeFalse()
        ->and(backtestResourceData($run, $user, BacktestSweepRunResource::class)['can_create_bot'])->toBeFalse();
})->with([
    'missing strategy configuration' => [function (array &$snapshot) {
        $snapshot['strategy']['configuration'] = [];
    }],
    'unknown strategy' => [function (array &$snapshot) {
        $snapshot['bot']['strategy_key'] = 'unknown-grid';
        $snapshot['strategy']['key'] = 'unknown-grid';
    }],
    'exchange outside allowlist' => [function (array &$snapshot) {
        $snapshot['bot']['exchange'] = 'kraken';
    }],
    'invalid symbol' => [function (array &$snapshot) {
        $snapshot['bot']['symbol'] = 'BTCUSDT';
    }],
    'zero capital' => [function (array &$snapshot) {
        $snapshot['bot']['capital_allocation'] = '0';
    }],
    'invalid levels' => [function (array &$snapshot) {
        $snapshot['strategy']['configuration']['levels_per_side'] = 0;
        unset($snapshot['strategy']['configuration']['level_count']);
    }],
]);
