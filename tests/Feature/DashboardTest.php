<?php

use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\EquitySnapshot;
use App\Models\Order;
use App\Models\User;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('exposes bot counts, aggregate profit and loss, and recent activity on the dashboard', function () {
    $user = User::factory()->create();

    $running = Bot::factory()->for($user)->running()->create([
        'realized_pnl' => '10',
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'settings' => ['mark_price' => '120'],
    ]);

    Bot::factory()->for($user)->create([
        'status' => BotStatus::Paused,
        'realized_pnl' => '-4',
    ]);

    Bot::factory()->for($user)->create([
        'status' => BotStatus::Idle,
        'realized_pnl' => '0',
    ]);

    Bot::factory()->create(['realized_pnl' => '999']);

    ActivityLog::create([
        'user_id' => $user->id,
        'bot_id' => $running->id,
        'type' => 'bot.started',
        'severity' => 'info',
        'message' => 'Bot started',
        'context' => [],
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('summary.total', 3)
            ->where('summary.running', 1)
            ->where('summary.paused', 1)
            ->where('summary.idle', 1)
            ->where('summary.stopped', 0)
            ->where('summary.realized_pnl', '6')
            ->where('summary.unrealized_pnl', '20')
            ->has('recentActivity', 1)
            ->where('recentActivity.0.type', 'bot.started')
            ->where('recentActivity.0.bot', $running->name)
            ->etc());
});

it('shows an empty dashboard for a user without bots', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('summary.total', 0)
            ->where('summary.realized_pnl', '0')
            ->where('summary.unrealized_pnl', '0')
            ->has('recentActivity', 0)
            ->where('equityHistory.range_days', 30)
            ->where('equityHistory.has_history', false)
            ->where('equityHistory.collecting', true)
            ->where('equityHistory.metrics', null)
            ->etc());
});

it('shares equity history for the default range with period metrics', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();

    EquitySnapshot::factory()->for($user)->at('2026-11-20 12:00:00')->create(['equity' => '1000']);
    EquitySnapshot::factory()->for($user)->at('2026-11-21 12:00:00')->create(['equity' => '1200']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('equityHistory.range_days', 30)
            ->where('equityHistory.available_ranges', [7, 30, 90])
            ->where('equityHistory.has_history', true)
            ->where('equityHistory.collecting', false)
            ->has('equityHistory.points', 2)
            ->where('equityHistory.points.0.equity', '1000.00000000')
            ->where('equityHistory.points.1.equity', '1200.00000000')
            ->where('equityHistory.metrics.period_return', '20.00')
            ->where('equityHistory.metrics.peak_equity', '1200.00000000')
            ->where('equityHistory.metrics.max_drawdown', '0.00')
            ->where('equityHistory.latest.equity', '1200.00000000')
            ->etc());
});

it('honors an explicit range and falls back for an invalid one', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();

    EquitySnapshot::factory()->for($user)->at('2026-11-14 12:00:00')->create(['equity' => '1000']);
    EquitySnapshot::factory()->for($user)->at('2026-11-20 12:00:00')->create(['equity' => '1100']);
    EquitySnapshot::factory()->for($user)->at('2026-11-21 12:00:00')->create(['equity' => '1200']);

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 7]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('equityHistory.range_days', 7)
            ->has('equityHistory.points', 2)
            ->etc());

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 999]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('equityHistory.range_days', 30)
            ->has('equityHistory.points', 3)
            ->etc());
});

it('reports one snapshot as collecting without metrics', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    EquitySnapshot::factory()->for($user)->at('2026-11-21 12:00:00')->create(['equity' => '1000']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('equityHistory.has_history', true)
            ->where('equityHistory.collecting', true)
            ->has('equityHistory.points', 1)
            ->where('equityHistory.metrics', null)
            ->etc());
});

it('renders the equity history controls, metrics, and collecting state', function () {
    $page = file_get_contents(resource_path('js/Pages/Dashboard.vue'));

    expect($page)
        ->toContain('Equity history')
        ->toContain('Period return')
        ->toContain('Peak equity')
        ->toContain('Maximum drawdown')
        ->toContain('Collecting history')
        ->toContain('equityHistory.available_ranges')
        ->toContain('EquityChart')
        ->toContain('60_000')
        ->toContain('router.get(')
        ->toContain("route('dashboard')")
        ->toContain('equityHistory.metrics.period_return === null')
        ->toContain('—');
});

it('allows the dashboard to size the equity chart', function () {
    $chart = file_get_contents(resource_path('js/Components/EquityChart.vue'));
    $dashboard = file_get_contents(resource_path('js/Pages/Dashboard.vue'));

    expect($chart)
        ->toContain("heightClass: { type: String, default: 'h-80' }")
        ->toContain(':class="heightClass"')
        ->and($dashboard)->toContain('height-class="h-56 sm:h-72 lg:h-80"');
});

it('renders the compact portfolio and operations layout', function () {
    $page = file_get_contents(resource_path('js/Pages/Dashboard.vue'));

    expect($page)
        ->toContain('equityHistory.latest')
        ->toContain('Account equity')
        ->toContain('equityHistory.collecting && equityHistory.has_history')
        ->toContain('All bots healthy')
        ->toContain('max-h-72')
        ->toContain('tabindex="0"')
        ->toContain('lg:col-span-8')
        ->toContain('lg:col-span-4')
        ->toContain('hidden sm:table-cell')
        ->not->toContain('<template #header>')
        ->not->toContain('py-12');
});

it('does not query equity history for a summary-only partial reload', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    EquitySnapshot::factory()->for($user)->at('2026-11-20 12:00:00')->create(['equity' => '1000']);
    EquitySnapshot::factory()->for($user)->at('2026-11-21 12:00:00')->create(['equity' => '1200']);

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'equity_snapshots')) {
            $queries[] = $query->sql;
        }
    });

    $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'summary,recentActivity',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])
        ->get(route('dashboard'))
        ->assertOk();

    expect($queries)->toBeEmpty();
});

it('omits the period return when the starting equity is not positive', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    EquitySnapshot::factory()->for($user)->at('2026-11-20 12:00:00')->create(['equity' => '0']);
    EquitySnapshot::factory()->for($user)->at('2026-11-21 12:00:00')->create(['equity' => '100']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('equityHistory.metrics.period_return', null)
            ->where('equityHistory.metrics.peak_equity', '100.00000000')
            ->where('equityHistory.metrics.max_drawdown', '0.00')
            ->etc());
});

it('never shares another owner equity history', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $other = User::factory()->create();

    EquitySnapshot::factory()->for($other)->at('2026-11-20 12:00:00')->create(['equity' => '9999']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('equityHistory.has_history', false)
            ->has('equityHistory.points', 0)
            ->etc());
});

it('supports a ninety-day range', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    EquitySnapshot::factory()->for($user)->at('2026-09-03 12:00:00')->create(['equity' => '100']);
    EquitySnapshot::factory()->for($user)->at('2026-11-17 12:00:00')->create(['equity' => '110']);

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 90]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('equityHistory.range_days', 90)
            ->has('equityHistory.points', 2)
            ->etc());

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 30]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('equityHistory.range_days', 30)
            ->has('equityHistory.points', 1)
            ->etc());
});

it('shares dashboard bot health for bots needing attention', function () {
    $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));

    $heartbeat = app(EngineHeartbeat::class);
    $heartbeat->available = true;
    $heartbeat->startedAt = '2026-11-26T11:00:00+00:00';

    $user = User::factory()->create();

    $paused = Bot::factory()->for($user)->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
        'last_error' => 'Live trading is disabled by TRADING_LIVE_ENABLED=false.',
        'last_successful_tick_at' => Carbon::parse('2026-11-26 11:59:00'),
    ]);

    $stale = Bot::factory()->for($user)->running()->create([
        'desired_status' => BotStatus::Running,
        'last_successful_tick_at' => Carbon::parse('2026-11-26 11:56:00'),
    ]);

    $error = Bot::factory()->for($user)->create([
        'status' => BotStatus::Error,
        'desired_status' => BotStatus::Running,
        'last_error' => 'Exchange rejected the order.',
        'last_successful_tick_at' => Carbon::parse('2026-11-26 11:59:00'),
    ]);

    Bot::factory()->for($user)->running()->create([
        'desired_status' => BotStatus::Running,
        'last_successful_tick_at' => Carbon::parse('2026-11-26 11:59:30'),
    ]);

    Bot::factory()->for($user)->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
        'last_error' => 'Archived bot.',
        'deleted_at' => Carbon::parse('2026-11-26 11:59:00'),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('botHealth', 3)
            ->where('botHealth.0.id', $paused->id)
            ->where('botHealth.0.issues', ['paused_by_engine'])
            ->where('botHealth.0.can_resume', true)
            ->where('botHealth.1.id', $stale->id)
            ->where('botHealth.1.issues', ['stale'])
            ->where('botHealth.1.can_resume', false)
            ->where('botHealth.2.id', $error->id)
            ->where('botHealth.2.issues', ['error'])
            ->where('botHealth.2.can_resume', false)
            ->etc());
});

it('does not mark bots stale without a fresh heartbeat or engine start time', function () {
    $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));

    $heartbeat = app(EngineHeartbeat::class);
    $heartbeat->available = false;

    $user = User::factory()->create();
    Bot::factory()->for($user)->running()->create([
        'desired_status' => BotStatus::Running,
        'last_successful_tick_at' => Carbon::parse('2026-11-26 11:50:00'),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('botHealth', 0)->etc());

    $heartbeat->available = true;
    $heartbeat->startedAt = null;

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('botHealth', 0)->etc());
});

it('does not mark a bot stale during engine restart grace', function () {
    $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));

    $heartbeat = app(EngineHeartbeat::class);
    $heartbeat->available = true;
    $heartbeat->startedAt = '2026-11-26T11:59:00+00:00';

    $user = User::factory()->create();
    Bot::factory()->for($user)->running()->create([
        'desired_status' => BotStatus::Running,
        'last_successful_tick_at' => Carbon::parse('2026-11-26 11:40:00'),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('botHealth', 0)->etc());
});

it('shares bot health in a partial reload', function () {
    $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));

    $heartbeat = app(EngineHeartbeat::class);
    $heartbeat->available = true;
    $heartbeat->startedAt = '2026-11-26T11:00:00+00:00';

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
        'last_error' => 'Paused by the engine.',
    ]);

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'summary,botHealth',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])
        ->get(route('dashboard'));

    $response->assertOk();

    expect($response->json('props.botHealth'))->toHaveCount(1)
        ->and($response->json('props.botHealth.0.id'))->toBe($bot->id);
});

it('surfaces a failed resume on the dashboard', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
    ]);

    $message = "Live trading is disabled by configuration; bot {$bot->id} cannot start in live mode.";

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('bots.start', $bot))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['action' => $message]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('errors.action', $message)->etc());
});

it('shows an expired engine heartbeat as a warning without mutating bots or orders', function () {
    $heartbeat = app(EngineHeartbeat::class);
    $heartbeat->available = false;
    $heartbeat->lastSeenAt = '2026-11-22T11:55:00+00:00';

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->create([
        'desired_status' => BotStatus::Running,
    ]);
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('engine.available', false)
            ->where('engine.last_seen_at', '2026-11-22T11:55:00+00:00')
            ->etc());

    expect($bot->fresh()->status)->toBe(BotStatus::Running)
        ->and($bot->fresh()->desired_status)->toBe(BotStatus::Running)
        ->and($order->fresh()->status)->toBe(OrderStatus::Open);
});
