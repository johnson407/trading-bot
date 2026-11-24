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
