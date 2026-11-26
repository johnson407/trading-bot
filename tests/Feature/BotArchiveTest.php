<?php

use App\Actions\ActivateKillSwitch;
use App\Domain\Analytics\EquitySnapshotter;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\BacktestRun;
use App\Models\BacktestSweep;
use App\Models\Bot;
use App\Models\EquitySnapshot;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('archives a stopped paper bot without open orders', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'name' => 'Retired grid',
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);

    $this->actingAs($user)
        ->delete(route('bots.destroy', $bot))
        ->assertRedirect(route('bots.index'))
        ->assertSessionHas('status', 'Bot Retired grid archived.');

    expect(Bot::query()->whereKey($bot->id)->exists())->toBeFalse()
        ->and(Bot::withTrashed()->findOrFail($bot->id)->trashed())->toBeTrue()
        ->and($bot->activityLogs()->where('type', 'bot.archived')->exists())->toBeTrue();
});

it('hides archived bots from the active list and shows them in the archived view', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'name' => 'Archived grid',
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);
    $bot->delete();

    $this->actingAs($user)
        ->get(route('bots.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('bots', 0)
            ->where('archived', false)
            ->etc());

    $this->actingAs($user)
        ->get(route('bots.index', ['archived' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('bots', 1)
            ->where('bots.0.name', 'Archived grid')
            ->where('archived', true)
            ->etc());
});

it('exposes archive eligibility on the detail page', function () {
    $user = User::factory()->create();
    $eligible = Bot::factory()->for($user)->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);

    $this->actingAs($user)
        ->get(route('bots.show', $eligible))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canArchive', true)->etc());

    $running = Bot::factory()->for($user)->create([
        'status' => BotStatus::Running,
        'desired_status' => BotStatus::Running,
    ]);

    $this->actingAs($user)
        ->get(route('bots.show', $running))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canArchive', false)->etc());

    $withOrder = Bot::factory()->for($user)->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);
    Order::factory()->for($withOrder)->create(['status' => 'open']);

    $this->actingAs($user)
        ->get(route('bots.show', $withOrder))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canArchive', false)->etc());

    app(EngineHeartbeat::class)->available = false;
    $live = Bot::factory()->for($user)->live()->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);

    $this->actingAs($user)
        ->get(route('bots.show', $live))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canArchive', false)->etc());
});

it('excludes archived bots from dashboard counts but keeps their profit and loss', function () {
    $user = User::factory()->create();
    $active = Bot::factory()->for($user)->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
        'realized_pnl' => '10',
        'quote_inventory' => '100',
    ]);
    $archived = Bot::factory()->for($user)->create([
        'name' => 'Archived grid',
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
        'realized_pnl' => '-4',
        'quote_inventory' => '50',
    ]);
    $archived->delete();

    ActivityLog::create([
        'user_id' => $user->id,
        'bot_id' => $archived->id,
        'type' => 'bot.archived',
        'severity' => 'info',
        'message' => 'Bot archived',
        'context' => [],
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('summary.stopped', 1)
            ->where('summary.realized_pnl', '6')
            ->where('summary.unrealized_pnl', '0')
            ->where('recentActivity.0.bot', 'Archived grid')
            ->where('recentActivity.0.archived', true)
            ->etc());

    expect($active->fresh()->trashed())->toBeFalse();
});

it('adds archived flags to alert payloads and keeps archived bots out of the filter', function () {
    $user = User::factory()->create();
    $archived = Bot::factory()->for($user)->create(['name' => 'Archived grid']);
    $archived->delete();

    Alert::factory()->for($user)->for($archived)->create();

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts.data', 1)
            ->where('alerts.data.0.bot.name', 'Archived grid')
            ->where('alerts.data.0.bot.archived', true)
            ->has('bots', 0)
            ->etc());

    $this->actingAs($user)
        ->get(route('alerts.index', ['bot_id' => $archived->id]))
        ->assertNotFound();
});

it('captures equity for an all-archived user including residual inventory', function () {
    $user = User::factory()->create();
    $archived = Bot::factory()->for($user)->create([
        'quote_inventory' => '500',
        'base_inventory' => '2',
        'average_entry_price' => '100',
        'realized_pnl' => '25',
        'fees_paid' => '1',
        'settings' => ['mark_price' => '150'],
    ]);
    $archived->delete();

    expect(app(EquitySnapshotter::class)->capture())->toBe(1);

    $snapshot = EquitySnapshot::query()->sole();

    expect($snapshot->user_id)->toBe($user->id)
        ->and($snapshot->quote_value)->toBe('500.00000000')
        ->and($snapshot->base_value)->toBe('300.00000000')
        ->and($snapshot->equity)->toBe('800.00000000')
        ->and($snapshot->realized_pnl)->toBe('25.00000000')
        ->and($snapshot->unrealized_pnl)->toBe('100.00000000')
        ->and($snapshot->bot_count)->toBe(1);
});

it('returns archived bots to the active list on restore without publishing a start', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'name' => 'Restored grid',
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);
    $bot->delete();

    $this->actingAs($user)
        ->post(route('bots.restore', $bot))
        ->assertRedirect(route('bots.index'));

    $this->actingAs($user)
        ->get(route('bots.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bots', 1)
            ->where('bots.0.name', 'Restored grid')
            ->etc());

    expect(app(CommandPublisher::class)->ofType('bot.start'))->toHaveCount(0);
});

it('returns 404 for archived bot detail and control routes', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);
    $order = Order::factory()->for($bot)->create();
    $bot->delete();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertNotFound();
    $this->actingAs($user)->get(route('bots.edit', $bot))->assertNotFound();
    $this->actingAs($user)->get(route('bots.activity', $bot))->assertNotFound();
    $this->actingAs($user)->post(route('bots.start', $bot))->assertNotFound();
    $this->actingAs($user)->post(route('bots.pause', $bot))->assertNotFound();
    $this->actingAs($user)->post(route('bots.stop', $bot))->assertNotFound();
    $this->actingAs($user)->post(route('bots.orders.cancel', [$bot, $order]))->assertNotFound();
    $this->actingAs($user)->patch(route('bots.exit-guard.update', $bot))->assertNotFound();
});

it('excludes archived bots from backtest and sweep selectors and stores', function () {
    $user = User::factory()->create();
    $archived = Bot::factory()->for($user)->create();
    StrategyConfig::factory()->for($archived)->create();
    $archived->delete();

    $this->actingAs($user)
        ->get(route('backtests.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('bots', 0)->etc());

    $this->actingAs($user)
        ->get(route('backtest-sweeps.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('bots', 0)->etc());

    $this->actingAs($user)
        ->post(route('backtests.store'), [
            'bot_id' => $archived->id,
            'timeframe' => '15m',
            'start_at' => now('UTC')->subDays(2)->format('Y-m-d'),
            'end_at' => now('UTC')->subDay()->format('Y-m-d'),
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('backtest-sweeps.store'), [
            'bot_id' => $archived->id,
            'timeframe' => '15m',
            'start_at' => now('UTC')->subDays(2)->format('Y-m-d'),
            'end_at' => now('UTC')->subDay()->format('Y-m-d'),
            'center_prices' => ['50000', '51000'],
            'spacing_values' => ['100'],
            'level_counts' => [4],
        ])
        ->assertSessionHasErrors('bot_id');

    expect(BacktestRun::query()->count())->toBe(0)
        ->and(BacktestSweep::query()->count())->toBe(0);
});

it('excludes archived bots from the kill switch', function () {
    $user = User::factory()->create();
    $active = Bot::factory()->for($user)->running()->create();
    $archived = Bot::factory()->for($user)->running()->create([
        'desired_status' => BotStatus::Running,
    ]);
    $archived->delete();

    $this->actingAs($user)
        ->get(route('kill-switch.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('botCount', 1)->etc());

    $result = app(ActivateKillSwitch::class)->handle($user);

    expect($active->fresh()->desired_status)->toBe(BotStatus::Paused)
        ->and($archived->fresh()->desired_status)->toBe(BotStatus::Running)
        ->and($result->botIds)->toBe([$active->id]);
});

it('keeps archived bot names on activity, alerts, backtests, and sweeps', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['name' => 'History grid']);
    $activity = ActivityLog::create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'bot.stopped',
        'severity' => 'info',
        'message' => 'Bot stopped',
        'context' => [],
        'created_at' => now(),
    ]);
    $alert = Alert::factory()->for($user)->for($bot)->create();
    $run = BacktestRun::factory()->for($user)->forBot($bot)->create();
    $sweep = BacktestSweep::factory()->for($user)->forBot($bot)->create();

    $bot->delete();

    expect($activity->fresh()->bot?->name)->toBe('History grid')
        ->and($alert->fresh()->bot?->name)->toBe('History grid')
        ->and($run->fresh()->bot?->name)->toBe('History grid')
        ->and($sweep->fresh()->bot?->name)->toBe('History grid');
});

it('rejects archiving a bot that is not fully stopped', function (string $status, string $desiredStatus) {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => $status,
        'desired_status' => $desiredStatus,
    ]);

    $this->actingAs($user)
        ->from(route('bots.show', $bot))
        ->delete(route('bots.destroy', $bot))
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasErrors('action');

    expect(Bot::query()->whereKey($bot->id)->exists())->toBeTrue();
})->with([
    'running' => [BotStatus::Running->value, BotStatus::Running->value],
    'paused' => [BotStatus::Paused->value, BotStatus::Paused->value],
    'error' => [BotStatus::Error->value, BotStatus::Error->value],
    'status mismatch' => [BotStatus::Running->value, BotStatus::Stopped->value],
    'desired mismatch' => [BotStatus::Stopped->value, BotStatus::Running->value],
]);

it('rejects archiving a stopped bot with an open order', function (string $orderStatus) {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);
    Order::factory()->for($bot)->create(['status' => $orderStatus]);

    $this->actingAs($user)
        ->delete(route('bots.destroy', $bot))
        ->assertSessionHasErrors('action');

    expect(Bot::query()->whereKey($bot->id)->exists())->toBeTrue();
})->with(['pending', 'open', 'partially_filled']);

it('rejects archiving a live bot without a fresh heartbeat', function () {
    app(EngineHeartbeat::class)->available = false;

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);

    $this->actingAs($user)
        ->delete(route('bots.destroy', $bot))
        ->assertSessionHasErrors('action');

    expect(Bot::query()->whereKey($bot->id)->exists())->toBeTrue();
});

it('archives a stopped live bot when the heartbeat is fresh', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);

    $this->actingAs($user)
        ->delete(route('bots.destroy', $bot))
        ->assertRedirect(route('bots.index'));

    expect(Bot::withTrashed()->findOrFail($bot->id)->trashed())->toBeTrue();
});

it('denies archiving another users bot', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);

    $this->actingAs($other)
        ->delete(route('bots.destroy', $bot))
        ->assertForbidden();

    expect(Bot::query()->whereKey($bot->id)->exists())->toBeTrue();
});

it('restores an archived bot without starting it', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'name' => 'Restored grid',
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);
    $bot->delete();

    $this->actingAs($user)
        ->post(route('bots.restore', $bot))
        ->assertRedirect(route('bots.index'))
        ->assertSessionHas('status', 'Bot Restored grid restored.');

    $restored = $bot->fresh();

    expect($restored)->not->toBeNull()
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->status)->toBe(BotStatus::Stopped)
        ->and($restored->desired_status)->toBe(BotStatus::Stopped)
        ->and($bot->activityLogs()->where('type', 'bot.restored')->exists())->toBeTrue();
});

it('rejects restoring a bot that is not archived', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('bots.restore', $bot))
        ->assertNotFound();
});

it('denies restoring another users archived bot', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create();
    $bot->delete();

    $this->actingAs($other)
        ->post(route('bots.restore', $bot))
        ->assertForbidden();

    expect(Bot::withTrashed()->findOrFail($bot->id)->trashed())->toBeTrue();
});
