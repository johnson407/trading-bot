<?php

use App\Domain\Backtest\Enums\BacktestStatus;
use App\Models\BacktestFill;
use App\Models\BacktestRun;
use App\Models\Bot;
use App\Models\RiskLimit;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Engine\CommandPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function ownedBot(User $user, array $bot = []): Bot
{
    $bot = Bot::factory()->for($user)->create($bot);
    StrategyConfig::factory()->for($bot)->create();

    return $bot;
}

it('creates and queues a backtest from an owned bot', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = ownedBot($user);

    $response = $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDays(1)->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ]);

    $run = BacktestRun::query()->first();

    $response->assertRedirect(route('backtests.show', $run));
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(BacktestStatus::Pending)
        ->and($run->config_snapshot['bot']['name'])->toBe($bot->name)
        ->and($run->config_snapshot['strategy']['configuration'])->toHaveKey('center_price');

    $published = app(CommandPublisher::class)->ofType('backtest.run');
    expect($published)->toHaveCount(1)
        ->and($published[0]['payload']['backtest_run_id'])->toBe($run->id);
    expect(RiskLimit::query()->count())->toBe(0);
});

it('does not let another user backtest a bot they do not own', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = ownedBot($owner);

    $this->actingAs($other)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDay()->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ])->assertForbidden();

    expect(BacktestRun::query()->count())->toBe(0);
    expect(app(CommandPublisher::class)->ofType('backtest.run'))->toHaveCount(0);
});

it('rejects an invalid date range and a future end date', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = ownedBot($user);

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->format('Y-m-d'),
        'end_at' => now('UTC')->subDay()->format('Y-m-d'),
    ])->assertSessionHasErrors('end_at');

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDay()->format('Y-m-d'),
        'end_at' => now('UTC')->addDay()->format('Y-m-d'),
    ])->assertSessionHasErrors('end_at');

    expect(BacktestRun::query()->count())->toBe(0);
});

it('renders the run list with owner-scoped summaries', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    BacktestRun::factory()->for($owner)->create(['name' => 'Owner run']);
    BacktestRun::factory()->for($other)->create(['name' => 'Other run']);

    $this->actingAs($owner)
        ->get(route('backtests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Index')
            ->has('runs', 1)
            ->where('runs.0.name', 'Owner run')
            ->where('active', true)
            ->etc()
        );
});

it('snapshots the bot exit rules and hydrates them for the replay', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = ownedBot($user, [
        'stop_loss_percent' => '4',
        'take_profit_percent' => '9',
        'range_low' => '80000',
        'range_high' => '120000',
    ]);

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDay()->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ])->assertRedirect();

    $run = BacktestRun::query()->firstOrFail();

    expect($run->config_snapshot['exit'])->toBe([
        'stop_loss_percent' => '4',
        'take_profit_percent' => '9',
        'range_low' => '80000',
        'range_high' => '120000',
    ]);
});

it('keeps exit rules in the snapshot after the bot rules change', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = ownedBot($user, [
        'stop_loss_percent' => '4',
        'take_profit_percent' => null,
        'range_low' => null,
        'range_high' => null,
    ]);

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDay()->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ]);

    $run = BacktestRun::query()->firstOrFail();
    $original = $run->config_snapshot['exit'];

    $bot->update(['stop_loss_percent' => '12', 'take_profit_percent' => '20']);

    expect($run->refresh()->config_snapshot['exit'])->toBe($original)
        ->and($original['stop_loss_percent'])->toBe('4');
});

it('rejects a range larger than the candle cap', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = ownedBot($user);

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '1m',
        'start_at' => now('UTC')->subYear()->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ])->assertSessionHasErrors('timeframe');

    expect(BacktestRun::query()->count())->toBe(0);
});

it('keeps the snapshot immutable after the bot changes', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = ownedBot($user);

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDay()->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ]);

    $run = BacktestRun::query()->firstOrFail();
    $original = $run->config_snapshot;

    $bot->update(['name' => 'Renamed', 'capital_allocation' => '250']);

    expect($run->refresh()->config_snapshot)->toBe($original)
        ->and(fn () => $run->update(['config_snapshot' => []]))
        ->toThrow(LogicException::class, 'A backtest snapshot is immutable.');
});

it('scopes run detail and deletion to the owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $run = BacktestRun::factory()->for($owner)->create();

    $this->actingAs($other)->get(route('backtests.show', $run))->assertForbidden();
    $this->actingAs($other)->delete(route('backtests.destroy', $run))->assertForbidden();

    $this->actingAs($owner)
        ->get(route('backtests.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Show')
            ->has('compareIds', 0)
            ->etc()
        );
});

it('displays protective exit outcomes on the backtest detail page', function () {
    $user = User::factory()->create();
    $reasons = [
        'stop_loss' => 'Stop-loss',
        'take_profit' => 'Take-profit',
        'range_exit' => 'Range exit',
    ];

    foreach ($reasons as $value => $label) {
        $run = BacktestRun::factory()->for($user)->completed()->create(['end_reason' => $value]);

        $this->actingAs($user)
            ->get(route('backtests.show', $run))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Backtests/Show')
                ->where('run.end_reason', $value)
                ->etc()
            );
    }

    $page = file_get_contents(resource_path('js/Pages/Backtests/Show.vue'));

    foreach ($reasons as $label) {
        expect($page)->toContain($label);
    }
});

it('rejects a bot without a strategy configuration or with an unavailable strategy', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDay()->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ])->assertSessionHasErrors('bot_id');

    StrategyConfig::factory()->for($bot)->create(['strategy_key' => 'unknown-strategy']);
    $bot->update(['strategy_key' => 'unknown-strategy']);

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDay()->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ])->assertSessionHasErrors('bot_id');

    expect(BacktestRun::query()->count())->toBe(0);
});

it('keeps a run readable after its bot is deleted and cascades fills on run deletion', function () {
    $user = User::factory()->create();
    $bot = ownedBot($user);
    $run = BacktestRun::factory()->for($user)->forBot($bot)->create();
    $fill = BacktestFill::factory()->for($run, 'run')->create();

    $bot->forceDelete();

    expect($run->refresh()->bot_id)->toBeNull()
        ->and(BacktestRun::query()->whereKey($run->id)->exists())->toBeTrue();

    $this->actingAs($user)->get(route('backtests.show', $run))->assertOk();

    $run->delete();

    expect(BacktestFill::query()->whereKey($fill->id)->exists())->toBeFalse();
});

it('keeps a run associated with an archived bot', function () {
    $user = User::factory()->create();
    $bot = ownedBot($user);
    $run = BacktestRun::factory()->for($user)->forBot($bot)->create();

    $bot->delete();

    expect($run->refresh()->bot_id)->toBe($bot->id)
        ->and($run->bot?->is($bot))->toBeTrue()
        ->and($run->bot?->name)->toBe($bot->name);

    $this->actingAs($user)->get(route('backtests.show', $run))->assertOk();
});

it('renders the create page with owned bot choices and defaults', function () {
    $user = User::factory()->create();
    $bot = ownedBot($user);

    $this->actingAs($user)
        ->get(route('backtests.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Create')
            ->has('bots', 1)
            ->where('bots.0.id', $bot->id)
            ->where('bots.0.has_configuration', true)
            ->has('timeframes', 6)
            ->where('defaults.timeframe', '15m')
            ->where('maxCandles', 200000)
            ->etc()
        );
});

it('renders a failed run without metrics and a completed comparison-ready run', function () {
    $user = User::factory()->create();
    $failed = BacktestRun::factory()->for($user)->create([
        'status' => BacktestStatus::Failed,
        'error' => 'No historical data available.',
        'metrics' => null,
        'equity_curve' => null,
    ]);
    $completed = BacktestRun::factory()->for($user)->completed()->create([
        'metrics' => ['return_percent' => '2.5', 'net_pnl' => '25'],
        'equity_curve' => [
            ['at' => '2026-01-01T00:00:00+00:00', 'equity' => '1000'],
            ['at' => '2026-01-01T00:15:00+00:00', 'equity' => '1025'],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('backtests.show', $failed))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Show')
            ->where('run.status', 'failed')
            ->where('run.error', 'No historical data available.')
            ->etc()
        );

    $this->actingAs($user)
        ->get(route('backtests.show', $completed))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Show')
            ->where('run.status', 'completed')
            ->has('fills', 0)
            ->etc()
        );
});

it('throttles backtest creation per user', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = ownedBot($user);
    RateLimiter::clear((string) $user->id);

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($user)->post(route('backtests.store'), [
            'bot_id' => $bot->id,
            'timeframe' => '15m',
            'start_at' => now('UTC')->subDay()->format('Y-m-d'),
            'end_at' => now('UTC')->format('Y-m-d'),
        ])->assertRedirect();
    }

    $this->actingAs($user)->post(route('backtests.store'), [
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDay()->format('Y-m-d'),
        'end_at' => now('UTC')->format('Y-m-d'),
    ])->assertStatus(429);
});

it('compares compatible runs and rejects incompatible ones', function () {
    $user = User::factory()->create();
    $base = [
        'symbol' => 'BTC/USDT',
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDays(2),
        'end_at' => now('UTC')->subDay(),
        'equity_curve' => [
            ['at' => '2026-01-01T00:00:00+00:00', 'equity' => '1000'],
            ['at' => '2026-01-01T00:15:00+00:00', 'equity' => '1010'],
        ],
        'metrics' => ['return_percent' => '1'],
    ];

    $first = BacktestRun::factory()->for($user)->completed()->create($base);
    $second = BacktestRun::factory()->for($user)->completed()->create($base);
    $third = BacktestRun::factory()->for($user)->completed()->create([
        ...$base,
        'timeframe' => '1h',
    ]);

    $this->actingAs($user)
        ->get(route('backtests.compare', ['ids' => [$first->id, $second->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Compare')
            ->has('runs', 2)
            ->where('runs.0.normalized_curve.1.value', '1.01')
            ->etc()
        );

    $this->actingAs($user)
        ->get(route('backtests.compare', ['ids' => [$first->id, $third->id]]))
        ->assertStatus(422);

    $differentRange = BacktestRun::factory()->for($user)->completed()->create([
        ...$base,
        'start_at' => now('UTC')->subDays(3),
        'end_at' => now('UTC')->subDays(2),
    ]);

    $this->actingAs($user)
        ->get(route('backtests.compare', ['ids' => [$first->id, $differentRange->id]]))
        ->assertStatus(422);

    $this->actingAs($user)
        ->get(route('backtests.compare', ['ids' => [$first->id, $first->id]]))
        ->assertSessionHasErrors('ids.1');
});
