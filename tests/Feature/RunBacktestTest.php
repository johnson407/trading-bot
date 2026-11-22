<?php

use App\Domain\Backtest\Engine\BacktestEngine;
use App\Domain\Backtest\Enums\BacktestEndReason;
use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\BacktestConfiguration;
use App\Domain\Backtest\ValueObjects\BacktestRiskLimits;
use App\Domain\Backtest\ValueObjects\Candle;
use App\Domain\Support\Decimal;
use App\Jobs\RunBacktest;
use App\Models\BacktestRun;
use App\Models\User;
use App\Services\Backtest\CandleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeCandleProvider;

uses(RefreshDatabase::class);

function runFor(?User $user = null): BacktestRun
{
    $user ??= User::factory()->create();
    $configuration = new BacktestConfiguration(
        botId: 1,
        botName: 'Job bot',
        exchange: 'binance',
        symbol: 'BTC/USDT',
        strategyKey: 'simple-grid',
        timeframe: Timeframe::M15,
        startAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        endAt: new DateTimeImmutable('2026-01-01T01:00:00Z'),
        capitalAllocation: Decimal::of('1000'),
        feeRate: Decimal::of('0.001'),
        strategyConfiguration: [
            'center_price' => '100',
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
            'levels_per_side' => 2,
        ],
        riskLimits: BacktestRiskLimits::make('1000', 20, '100'),
    );

    return BacktestRun::query()->create([
        'user_id' => $user->id,
        'bot_id' => null,
        'name' => 'Job run',
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'timeframe' => '15m',
        'start_at' => new DateTimeImmutable('2026-01-01T00:00:00Z'),
        'end_at' => new DateTimeImmutable('2026-01-01T01:00:00Z'),
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'config_snapshot' => $configuration->toSnapshot(),
        'status' => BacktestStatus::Pending,
    ]);
}

it('runs a queued backtest and stores metrics, fills, and progress', function () {
    $run = runFor();
    $provider = new FakeCandleProvider([
        Candle::make('2026-01-01T00:00:00Z', 100, 100, 100, 100, 1),
        Candle::make('2026-01-01T00:15:00Z', 90, 90, 90, 90, 1),
        Candle::make('2026-01-01T00:30:00Z', 110, 110, 110, 110, 1),
        Candle::make('2026-01-01T00:45:00Z', 110, 110, 110, 110, 1),
    ]);

    (new RunBacktest($run->id))->handle(
        new CandleStore($provider),
        app(BacktestEngine::class),
    );

    $run->refresh();

    expect($run->error)->toBeNull()
        ->and($run->status)->toBe(BacktestStatus::Completed)
        ->and($run->end_reason)->toBe(BacktestEndReason::RangeEnd)
        ->and($run->progress)->toBe(100)
        ->and($run->candles_processed)->toBe(4)
        ->and($run->metrics['fill_count'])->toBe(2)
        ->and($run->equity_curve)->toHaveCount(4)
        ->and($run->fills()->count())->toBe(2);
});

it('does not run a completed backtest twice', function () {
    $run = runFor();
    $run->update(['status' => BacktestStatus::Completed]);

    (new RunBacktest($run->id))->handle(
        new CandleStore(new FakeCandleProvider),
        app(BacktestEngine::class),
    );

    expect($run->refresh()->status)->toBe(BacktestStatus::Completed)
        ->and($run->metrics)->toBeNull();
});

it('marks a run failed when no candles are returned', function () {
    $run = runFor();

    (new RunBacktest($run->id))->handle(
        new CandleStore(new FakeCandleProvider),
        app(BacktestEngine::class),
    );

    $run->refresh();

    expect($run->status)->toBe(BacktestStatus::Failed)
        ->and($run->error)->toBe('No historical data available.');
});

it('marks a pending or running run failed when the job itself fails', function () {
    $run = runFor();
    $run->update(['status' => BacktestStatus::Running, 'started_at' => now()->subMinutes(20)]);
    DB::table('backtest_runs')->where('id', $run->id)->update(['updated_at' => now()->subMinutes(20)]);

    (new RunBacktest($run->id))->failed(new RuntimeException('Worker timed out.'));

    expect($run->refresh()->status)->toBe(BacktestStatus::Failed)
        ->and($run->error)->toBe('Worker timed out.')
        ->and($run->finished_at)->not->toBeNull();
});

it('fails stale running and aged pending runs whose worker disappeared', function () {
    $staleRunning = runFor();
    $staleRunning->update(['status' => BacktestStatus::Running, 'started_at' => now()->subMinutes(20)]);
    DB::table('backtest_runs')->where('id', $staleRunning->id)->update(['updated_at' => now()->subMinutes(20)]);

    $stalePending = runFor();
    DB::table('backtest_runs')->where('id', $stalePending->id)->update(['created_at' => now()->subMinutes(20)]);

    $freshRunning = runFor();
    $freshRunning->update(['status' => BacktestStatus::Running, 'started_at' => now()->subMinute()]);

    $freshPending = runFor();

    $this->artisan('backtests:fail-stale')->assertSuccessful();

    expect($staleRunning->refresh()->status)->toBe(BacktestStatus::Failed)
        ->and($staleRunning->error)->toBe('The backtest worker stopped before the run finished.')
        ->and($stalePending->refresh()->status)->toBe(BacktestStatus::Failed)
        ->and($stalePending->error)->toBe('The backtest worker stopped before the run finished.')
        ->and($stalePending->finished_at)->not->toBeNull()
        ->and($freshRunning->refresh()->status)->toBe(BacktestStatus::Running)
        ->and($freshPending->refresh()->status)->toBe(BacktestStatus::Pending);
});
