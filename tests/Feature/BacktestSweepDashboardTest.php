<?php

use App\Models\BacktestRun;
use App\Models\BacktestSweep;
use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Engine\CommandPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function sweepDashboardBot(User $user, array $bot = []): Bot
{
    $bot = Bot::factory()->for($user)->create($bot);
    StrategyConfig::factory()->for($bot)->create();

    return $bot;
}

function sweepDashboardPayload(Bot $bot, array $overrides = []): array
{
    return array_merge([
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDays(2)->format('Y-m-d'),
        'end_at' => now('UTC')->subDay()->format('Y-m-d'),
        'name' => 'Dashboard sweep',
        'center_prices' => ['50000', '51000'],
        'spacing_values' => ['100', '200'],
        'level_counts' => [4, 5],
    ], $overrides);
}

it('creates a sweep from the dashboard and redirects to its detail page', function () {
    Queue::fake();
    $user = User::factory()->create();
    $bot = sweepDashboardBot($user);

    $response = $this->actingAs($user)->post(
        route('backtest-sweeps.store'),
        sweepDashboardPayload($bot),
    );

    $sweep = BacktestSweep::query()->firstOrFail();

    $response->assertRedirect(route('backtest-sweeps.show', $sweep));

    expect($sweep->user_id)->toBe($user->id)
        ->and($sweep->bot_id)->toBe($bot->id)
        ->and($sweep->name)->toBe('Dashboard sweep')
        ->and($sweep->total_runs)->toBe(8)
        ->and($sweep->runs()->count())->toBe(8)
        ->and($sweep->runs()->where('user_id', $user->id)->count())->toBe(8);

    $published = app(CommandPublisher::class)->ofType('backtest.sweep');
    expect($published)->toHaveCount(1)
        ->and($published[0]['payload']['backtest_sweep_id'])->toBe($sweep->id);
});

it('reports a sweep publish failure instead of claiming it was queued', function () {
    $user = User::factory()->create();
    $bot = sweepDashboardBot($user);
    app(CommandPublisher::class)->fail = true;

    $response = $this->actingAs($user)->post(
        route('backtest-sweeps.store'),
        sweepDashboardPayload($bot),
    );

    $sweep = BacktestSweep::query()->firstOrFail();

    $response->assertRedirect(route('backtest-sweeps.show', $sweep))
        ->assertSessionHasErrors('backtest');

    expect($sweep->runs()->where('status', 'failed')->count())->toBe(8);
});

it('rejects invalid or foreign sweep requests without creating rows', function () {
    Queue::fake();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $bot = sweepDashboardBot($user);
    $foreign = sweepDashboardBot($other);

    $this->actingAs($user)->post(route('backtest-sweeps.store'), sweepDashboardPayload($bot, [
        'center_prices' => ['50000', '51000', '52000', '53000'],
        'spacing_values' => ['100', '200', '300'],
        'level_counts' => [4, 5, 6],
    ]))->assertSessionHasErrors('parameters');

    $this->actingAs($user)->post(
        route('backtest-sweeps.store'),
        sweepDashboardPayload($foreign),
    )->assertSessionHasErrors('bot_id');

    expect(BacktestSweep::query()->count())->toBe(0)
        ->and(BacktestRun::query()->count())->toBe(0);

    expect(app(CommandPublisher::class)->ofType('backtest.sweep'))->toHaveCount(0);
});

it('lists only the owner sweeps newest first with derived counts and status', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = sweepDashboardBot($owner);
    $old = BacktestSweep::factory()->for($owner)->forBot($bot)->create([
        'name' => 'Older sweep',
        'created_at' => now('UTC')->subDays(2),
    ]);
    $new = BacktestSweep::factory()->for($owner)->forBot($bot)->create([
        'name' => 'Newest sweep',
        'total_runs' => 4,
    ]);
    BacktestSweep::factory()->for($other)->create(['name' => 'Other sweep']);

    BacktestRun::factory()->for($owner)->forBot($bot)->create([
        'sweep_id' => $new->id,
        'name' => 'Completed run',
        'status' => 'completed',
        'progress' => 100,
    ]);
    BacktestRun::factory()->for($owner)->forBot($bot)->create([
        'sweep_id' => $new->id,
        'name' => 'Failed run',
        'status' => 'failed',
        'progress' => 100,
    ]);
    BacktestRun::factory()->for($owner)->forBot($bot)->create([
        'sweep_id' => $new->id,
        'name' => 'Pending run',
        'status' => 'pending',
        'progress' => 0,
    ]);
    BacktestRun::factory()->for($owner)->forBot($bot)->create([
        'sweep_id' => $new->id,
        'name' => 'Running run',
        'status' => 'running',
        'progress' => 25,
    ]);

    $this->actingAs($owner)
        ->get(route('backtest-sweeps.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Sweeps/Index')
            ->has('sweeps', 2)
            ->where('sweeps.0.id', $new->id)
            ->where('sweeps.0.name', 'Newest sweep')
            ->where('sweeps.0.total_runs', 4)
            ->where('sweeps.0.completed_runs_count', 1)
            ->where('sweeps.0.failed_runs_count', 1)
            ->where('sweeps.0.active_runs_count', 2)
            ->where('sweeps.0.running_runs_count', 1)
            ->where('sweeps.0.status', 'running')
            ->where('sweeps.0.progress', 50)
            ->where('sweeps.1.id', $old->id)
            ->where('active', true)
            ->etc()
        );
});

it('renders an empty sweep list', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('backtest-sweeps.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Sweeps/Index')
            ->has('sweeps', 0)
            ->where('active', false)
            ->etc()
        );
});

it('renders the sweep create page with owned configured grid bots and the cap', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $grid = sweepDashboardBot($user, ['name' => 'Owned grid']);
    sweepDashboardBot($user, ['name' => 'Not a grid', 'strategy_key' => 'fake']);
    Bot::factory()->for($user)->create(['name' => 'Unconfigured grid', 'strategy_key' => 'simple-grid']);
    sweepDashboardBot($other, ['name' => 'Foreign grid']);

    $this->actingAs($user)
        ->get(route('backtest-sweeps.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Sweeps/Create')
            ->has('bots', 1)
            ->where('bots.0.id', $grid->id)
            ->where('bots.0.name', 'Owned grid')
            ->where('bots.0.has_configuration', true)
            ->has('timeframes', 6)
            ->where('defaults.timeframe', '15m')
            ->has('presets', 3)
            ->where('maxCombinations', 24)
            ->where('maxCandles', 200000)
            ->etc()
        );
});

it('shows sweep runs, derived progress, and a compare url for completed runs', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = sweepDashboardBot($owner);
    $sweep = BacktestSweep::factory()->for($owner)->forBot($bot)->create([
        'name' => 'Detail sweep',
        'total_runs' => 5,
    ]);

    $completedIds = collect(range(1, 3))->map(fn (int $index) => BacktestRun::factory()
        ->for($owner)
        ->forBot($bot)
        ->completed()
        ->create([
            'sweep_id' => $sweep->id,
            'name' => "Completed {$index}",
            'config_snapshot' => array_replace_recursive(
                BacktestRun::factory()->make()->config_snapshot,
                ['strategy' => ['configuration' => [
                    'center_price' => '50000',
                    'spacing_value' => '100',
                    'level_count' => 4,
                    'levels_per_side' => 4,
                ]]],
            ),
        ])->id);

    BacktestRun::factory()->for($owner)->forBot($bot)->create([
        'sweep_id' => $sweep->id,
        'name' => 'Failed run',
        'status' => 'failed',
        'progress' => 100,
        'error' => 'No historical data available.',
    ]);

    $running = BacktestRun::factory()->for($owner)->forBot($bot)->create([
        'sweep_id' => $sweep->id,
        'name' => 'Running run',
        'status' => 'running',
        'progress' => 40,
        'config_snapshot' => array_replace_recursive(
            BacktestRun::factory()->make()->config_snapshot,
            ['strategy' => ['configuration' => [
                'center_price' => '51000',
                'spacing_value' => '200',
                'level_count' => 6,
                'levels_per_side' => 6,
            ]]],
        ),
    ]);

    $expectedCompareUrl = route('backtests.compare', ['ids' => $completedIds->reverse()->values()->all()]);

    $this->actingAs($owner)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Sweeps/Show')
            ->where('sweep.id', $sweep->id)
            ->where('sweep.status', 'running')
            ->where('sweep.progress', 80)
            ->where('sweep.completed_runs_count', 3)
            ->where('sweep.failed_runs_count', 1)
            ->where('sweep.active_runs_count', 1)
            ->has('runs', 5)
            ->where('runs.0.id', $running->id)
            ->where('runs.0.status', 'running')
            ->where('runs.0.progress', 40)
            ->where('runs.0.swept.center_price', '51000')
            ->where('runs.0.swept.spacing_value', '200')
            ->where('runs.0.swept.level_count', 6)
            ->where('compareUrl', $expectedCompareUrl)
            ->where('active', true)
            ->etc()
        );

    $this->actingAs($other)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertNotFound();
});

it('marks only usable completed sweep runs as create bot sources', function () {
    $owner = User::factory()->create();
    $bot = sweepDashboardBot($owner);
    $sweep = BacktestSweep::factory()->for($owner)->forBot($bot)->create(['total_runs' => 2]);

    $completed = BacktestRun::factory()->for($owner)->forBot($bot)->completedSource()->create([
        'sweep_id' => $sweep->id,
        'created_at' => now()->subMinute(),
    ]);

    $failed = BacktestRun::factory()->for($owner)->forBot($bot)->completedSource()->create([
        'sweep_id' => $sweep->id,
        'status' => 'failed',
        'created_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Sweeps/Show')
            ->has('runs', 2)
            ->where('runs.0.id', $failed->id)
            ->where('runs.0.can_create_bot', false)
            ->where('runs.1.id', $completed->id)
            ->where('runs.1.can_create_bot', true)
            ->etc()
        );
});

it('omits the compare url until two runs complete', function () {
    $user = User::factory()->create();
    $bot = sweepDashboardBot($user);
    $sweep = BacktestSweep::factory()->for($user)->forBot($bot)->create(['total_runs' => 2]);

    BacktestRun::factory()->for($user)->forBot($bot)->completed()->create(['sweep_id' => $sweep->id]);
    BacktestRun::factory()->for($user)->forBot($bot)->create([
        'sweep_id' => $sweep->id,
        'status' => 'failed',
    ]);

    $this->actingAs($user)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backtests/Sweeps/Show')
            ->where('compareUrl', null)
            ->where('active', false)
            ->etc()
        );
});

it('exposes the sweep routes and links them from the backtests index', function () {
    expect(route('backtest-sweeps.index', absolute: false))->toBe('/backtests/sweeps')
        ->and(route('backtest-sweeps.create', absolute: false))->toBe('/backtests/sweeps/create')
        ->and(route('backtest-sweeps.store', absolute: false))->toBe('/backtests/sweeps')
        ->and(route('backtest-sweeps.show', ['sweep' => 1], absolute: false))->toBe('/backtests/sweeps/1');

    $page = file_get_contents(resource_path('js/Pages/Backtests/Index.vue'));

    expect($page)->toContain("route('backtest-sweeps.index')");
});

it('renders sweep list labels, progress, and polling props', function () {
    $page = file_get_contents(resource_path('js/Pages/Backtests/Sweeps/Index.vue'));

    foreach ([
        'Parameter sweeps',
        'Combinations',
        'Progress',
        'Bot / symbol',
        "route('backtest-sweeps.show'",
        "route('backtest-sweeps.create'",
        'sweeps: { type: Array',
        'active: { type: Boolean',
    ] as $label) {
        expect($page)->toContain($label);
    }
});

it('renders the sweep create form, live count, cap warning, and store target', function () {
    $page = file_get_contents(resource_path('js/Pages/Backtests/Sweeps/Create.vue'));

    foreach ([
        'center_prices',
        'spacing_values',
        'level_counts',
        'combinationCount',
        'maxCombinations',
        'above the maximum',
        "route('backtest-sweeps.store')",
        'maxCombinations: { type: Number',
    ] as $label) {
        expect($page)->toContain($label);
    }
});

it('renders sweep detail outcomes, polling props, run links, and compare action', function () {
    $page = file_get_contents(resource_path('js/Pages/Backtests/Sweeps/Show.vue'));

    foreach ([
        'Swept values',
        'Max drawdown',
        'End reason',
        'compareUrl',
        "only: ['sweep', 'runs', 'compareUrl', 'active']",
        "route('backtests.show'",
        "route('bots.create'",
        'Create paper bot',
        'source_backtest_run_id',
        'Compare completed',
    ] as $label) {
        expect($page)->toContain($label);
    }
});

it('recomputes completed and incomplete status after runs are deleted', function () {
    $user = User::factory()->create();
    $bot = sweepDashboardBot($user);
    $sweep = BacktestSweep::factory()->for($user)->forBot($bot)->create(['total_runs' => 4]);

    foreach (range(1, 3) as $index) {
        BacktestRun::factory()->for($user)->forBot($bot)->completed()->create([
            'sweep_id' => $sweep->id,
            'name' => "Completed {$index}",
        ]);
    }

    $failed = BacktestRun::factory()->for($user)->forBot($bot)->create([
        'sweep_id' => $sweep->id,
        'name' => 'Failed run',
        'status' => 'failed',
        'progress' => 100,
    ]);

    $this->actingAs($user)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sweep.status', 'failed')
            ->where('sweep.runs_count', 4)
            ->where('active', false)
            ->etc()
        );

    $failed->delete();

    $this->actingAs($user)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sweep.status', 'completed')
            ->where('sweep.runs_count', 3)
            ->where('sweep.completed_runs_count', 3)
            ->where('sweep.progress', 100)
            ->where('active', false)
            ->etc()
        );

    BacktestRun::query()->where('sweep_id', $sweep->id)->delete();

    $this->actingAs($user)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sweep.status', 'incomplete')
            ->where('sweep.runs_count', 0)
            ->where('sweep.progress', 100)
            ->where('active', false)
            ->etc()
        );
});

it('truncates the compare url to four completed runs', function () {
    $user = User::factory()->create();
    $bot = sweepDashboardBot($user);
    $sweep = BacktestSweep::factory()->for($user)->forBot($bot)->create(['total_runs' => 5]);

    $ids = collect(range(1, 5))->map(fn (int $index) => BacktestRun::factory()
        ->for($user)
        ->forBot($bot)
        ->completed()
        ->create(['sweep_id' => $sweep->id, 'name' => "Completed {$index}"])
        ->id);

    $expected = route('backtests.compare', [
        'ids' => $ids->reverse()->take(4)->values()->all(),
    ]);

    $this->actingAs($user)
        ->get(route('backtest-sweeps.show', $sweep))
        ->assertInertia(fn (Assert $page) => $page
            ->where('compareUrl', $expected)
            ->etc()
        );
});

it('redirects guests away from every sweep route', function () {
    $sweep = BacktestSweep::factory()->create();

    $this->get(route('backtest-sweeps.index'))->assertRedirect(route('login'));
    $this->get(route('backtest-sweeps.create'))->assertRedirect(route('login'));
    $this->get(route('backtest-sweeps.show', $sweep))->assertRedirect(route('login'));
    $this->post(route('backtest-sweeps.store'), [])->assertRedirect(route('login'));
});
