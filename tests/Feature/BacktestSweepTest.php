<?php

use App\Actions\CreateBacktestSweep;
use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Backtest\Enums\Timeframe;
use App\Models\BacktestRun;
use App\Models\BacktestSweep;
use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Engine\CommandPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function sweepBot(User $user, array $bot = []): Bot
{
    $bot = Bot::factory()->for($user)->create($bot);
    StrategyConfig::factory()->for($bot)->create();

    return $bot;
}

function createSweep(Bot $bot, array $parameters, ?User $user = null): BacktestSweep
{
    $user ??= $bot->user;

    return app(CreateBacktestSweep::class)->handle(
        $user,
        $bot,
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-02T00:00:00Z'),
        $parameters,
        'Grid sweep',
    );
}

it('creates the cartesian product of sweep values as grouped runs', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = sweepBot($user);

    $sweep = createSweep($bot, [
        'center_prices' => ['50000', '51000'],
        'spacing_values' => ['100', '200'],
        'level_counts' => [4, 5],
    ]);

    expect(BacktestSweep::query()->count())->toBe(1)
        ->and($sweep->total_runs)->toBe(8)
        ->and($sweep->parameters)->toBe([
            'center_prices' => ['50000', '51000'],
            'spacing_values' => ['100', '200'],
            'level_counts' => [4, 5],
        ])
        ->and($sweep->runs()->count())->toBe(8)
        ->and($sweep->runs()->whereNull('sweep_id')->count())->toBe(0);

    $runs = $sweep->runs()->orderBy('id')->get();

    $combinations = $runs->map(fn (BacktestRun $run) => [
        $run->config_snapshot['strategy']['configuration']['center_price'],
        $run->config_snapshot['strategy']['configuration']['spacing_value'],
        $run->config_snapshot['strategy']['configuration']['level_count'],
        $run->config_snapshot['strategy']['configuration']['levels_per_side'],
    ])->all();

    expect($combinations)->toBe([
        ['50000', '100', 4, 4],
        ['50000', '100', 5, 5],
        ['50000', '200', 4, 4],
        ['50000', '200', 5, 5],
        ['51000', '100', 4, 4],
        ['51000', '100', 5, 5],
        ['51000', '200', 4, 4],
        ['51000', '200', 5, 5],
    ]);

    $run = $runs->first();

    expect($run->strategy_key)->toBe('simple-grid')
        ->and($run->name)->toContain('50000')
        ->and($run->config_snapshot['strategy']['configuration']['order_quantity'])->toBe('0.001')
        ->and($run->config_snapshot['strategy']['configuration']['spacing_type'])->toBe('arithmetic')
        ->and($run->config_snapshot['bot']['name'])->toBe($bot->name);

    $published = app(CommandPublisher::class)->ofType('backtest.sweep');
    expect($published)->toHaveCount(1)
        ->and($published[0]['payload']['backtest_sweep_id'])->toBe($sweep->id);
});

it('rolls the sweep and all runs back when a run cannot be created', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = sweepBot($user);
    $created = 0;

    BacktestRun::creating(function () use (&$created): void {
        $created++;

        if ($created === 2) {
            throw new RuntimeException('Cannot create run.');
        }
    });

    expect(fn () => createSweep($bot, [
        'center_prices' => ['50000', '51000'],
        'spacing_values' => ['100'],
        'level_counts' => [4, 5],
    ]))->toThrow(RuntimeException::class, 'Cannot create run.');

    expect(BacktestSweep::query()->count())->toBe(0)
        ->and(BacktestRun::query()->count())->toBe(0);

    expect(app(CommandPublisher::class)->ofType('backtest.sweep'))->toHaveCount(0);
});

it('marks runs failed when a publish fails and leaves the sweep terminal', function () {
    $user = User::factory()->create();
    $bot = sweepBot($user);
    Log::spy();

    app(CommandPublisher::class)->fail = true;

    $sweep = createSweep($bot, [
        'center_prices' => ['50000', '51000'],
        'spacing_values' => ['100'],
        'level_counts' => [4],
    ]);

    $runs = $sweep->runs()->get();

    expect($runs)->toHaveCount(2)
        ->and($runs->where('status', BacktestStatus::Failed)->count())->toBe(2)
        ->and($runs->where('status', BacktestStatus::Pending)->count())->toBe(0)
        ->and($runs->every(fn (BacktestRun $run) => $run->error === 'The backtest job could not be queued.'))->toBeTrue()
        ->and($runs->every(fn (BacktestRun $run) => $run->finished_at !== null))->toBeTrue();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'backtest.sweep_publish_failed'
            && $context['backtest_sweep_id'] === $sweep->id)
        ->once();

    $this->actingAs($user)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Backtests/Sweeps/Show')
            ->where('sweep.status', 'failed')
            ->where('sweep.failed_runs_count', 2)
            ->where('sweep.active_runs_count', 0)
            ->where('sweep.progress', 100)
            ->where('active', false)
            ->etc()
        );
});

it('rejects a sweep action for a foreign bot, dynamic grid, or malformed values', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $dynamic = sweepBot($user, ['strategy_key' => 'dynamic-grid']);
    $foreign = sweepBot($other);
    $bot = sweepBot($user);
    $parameters = [
        'center_prices' => ['50000', '51000'],
        'spacing_values' => ['100'],
        'level_counts' => [4],
    ];

    expect(BacktestSweep::GRID_STRATEGIES)->toBe([
        'simple-grid',
        'trailing-grid',
        'martingale-grid',
    ])
        ->and(fn () => createSweep($dynamic, $parameters))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createSweep($foreign, $parameters, $user))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createSweep($bot, [
            'center_prices' => [true, '51000'],
            'spacing_values' => ['100'],
            'level_counts' => [4],
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createSweep($bot, [
            'center_prices' => ['1.00000000000000001', '51000'],
            'spacing_values' => ['100'],
            'level_counts' => [4],
        ]))->toThrow(InvalidArgumentException::class);
});
