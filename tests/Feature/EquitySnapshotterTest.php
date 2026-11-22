<?php

use App\Domain\Analytics\EquitySnapshotter;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\EquitySnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function snapshotBot(User $user, array $attributes): Bot
{
    return Bot::factory()->for($user)->create($attributes);
}

it('sums quote, mark-to-market base, pnl, fees, and bot count for one owner', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:07:30'));

    $user = User::factory()->create();
    snapshotBot($user, [
        'quote_inventory' => '1000',
        'base_inventory' => '2',
        'average_entry_price' => '100',
        'realized_pnl' => '50',
        'fees_paid' => '5',
        'settings' => ['mark_price' => '110'],
    ]);
    snapshotBot($user, [
        'quote_inventory' => '500',
        'base_inventory' => '0',
        'average_entry_price' => '0',
        'realized_pnl' => '-10',
        'fees_paid' => '1',
        'settings' => ['mark_price' => '120'],
    ]);
    snapshotBot($user, [
        'quote_inventory' => '300',
        'base_inventory' => '1',
        'average_entry_price' => '200',
        'realized_pnl' => '0',
        'fees_paid' => '2',
        'settings' => [],
    ]);

    $count = app(EquitySnapshotter::class)->capture();

    $snapshot = EquitySnapshot::query()->sole();

    expect($count)->toBe(1)
        ->and($snapshot->user_id)->toBe($user->id)
        ->and($snapshot->captured_at->toDateTimeString())->toBe('2026-11-22 12:05:00')
        ->and($snapshot->equity)->toBe('2220.00000000')
        ->and($snapshot->quote_value)->toBe('1800.00000000')
        ->and($snapshot->base_value)->toBe('420.00000000')
        ->and($snapshot->realized_pnl)->toBe('40.00000000')
        ->and($snapshot->unrealized_pnl)->toBe('20.00000000')
        ->and($snapshot->fees_paid)->toBe('8.00000000')
        ->and($snapshot->bot_count)->toBe(3)
        ->and(Decimal::of($snapshot->quote_value)->add(Decimal::of($snapshot->base_value))->toString())
        ->toBe(Decimal::of($snapshot->equity)->toString());
});

it('falls back to average entry and counts quote-only bots', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:01:00'));

    $user = User::factory()->create();
    snapshotBot($user, [
        'quote_inventory' => '100',
        'base_inventory' => '2',
        'average_entry_price' => '50',
        'settings' => [],
    ]);
    snapshotBot($user, [
        'quote_inventory' => '75',
        'base_inventory' => '0',
        'average_entry_price' => '0',
        'settings' => [],
    ]);
    snapshotBot($user, [
        'quote_inventory' => '25',
        'base_inventory' => '1',
        'average_entry_price' => '0',
        'settings' => [],
    ]);

    app(EquitySnapshotter::class)->capture();

    $snapshot = EquitySnapshot::query()->sole();

    expect($snapshot->quote_value)->toBe('200.00000000')
        ->and($snapshot->base_value)->toBe('100.00000000')
        ->and($snapshot->equity)->toBe('300.00000000')
        ->and($snapshot->unrealized_pnl)->toBe('0.00000000')
        ->and($snapshot->bot_count)->toBe(3);
});

it('collapses repeated captures in the same interval bucket', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:07:30'));

    $user = User::factory()->create();
    $bot = snapshotBot($user, [
        'quote_inventory' => '100',
        'base_inventory' => '1',
        'average_entry_price' => '10',
        'settings' => ['mark_price' => '20'],
    ]);

    app(EquitySnapshotter::class)->capture();

    $bot->forceFill([
        'quote_inventory' => '200',
        'settings' => ['mark_price' => '30'],
    ])->save();

    app(EquitySnapshotter::class)->capture();

    $snapshot = EquitySnapshot::query()->sole();

    expect($snapshot->equity)->toBe('230.00000000')
        ->and(EquitySnapshot::query()->count())->toBe(1);
});

it('skips users without bots and captures each account separately', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:07:30'));

    $empty = User::factory()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    snapshotBot($first, [
        'quote_inventory' => '100',
        'base_inventory' => '1',
        'average_entry_price' => '10',
        'settings' => ['mark_price' => '20'],
    ]);
    snapshotBot($second, [
        'quote_inventory' => '500',
        'base_inventory' => '0',
        'average_entry_price' => '0',
        'settings' => [],
    ]);

    $count = app(EquitySnapshotter::class)->capture();

    $byUser = EquitySnapshot::query()->orderBy('user_id')->get()->keyBy('user_id');

    expect($count)->toBe(2)
        ->and($byUser->has($empty->id))->toBeFalse()
        ->and($byUser[$first->id]->equity)->toBe('120.00000000')
        ->and($byUser[$second->id]->equity)->toBe('500.00000000');
});

it('isolates a failing account from the rest', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:07:30'));

    Log::spy();

    $bad = User::factory()->create();
    $good = User::factory()->create();
    snapshotBot($bad, ['quote_inventory' => '100']);
    snapshotBot($good, ['quote_inventory' => '200']);

    $snapshotter = new class($bad->id) extends EquitySnapshotter
    {
        public function __construct(private readonly int $failForUserId) {}

        protected function snapshotUser(User $user, CarbonImmutable $capturedAt): void
        {
            if ($user->id === $this->failForUserId) {
                throw new RuntimeException('boom');
            }

            parent::snapshotUser($user, $capturedAt);
        }
    };

    $count = $snapshotter->capture();

    expect($count)->toBe(1)
        ->and(EquitySnapshot::query()->where('user_id', $bad->id)->exists())->toBeFalse()
        ->and(EquitySnapshot::query()->where('user_id', $good->id)->exists())->toBeTrue();

    Log::shouldHaveReceived('error');
});

it('uses one bucket for every account in a run and respects a custom interval', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:17:30'));
    config(['analytics.capture_interval_minutes' => 10]);

    $first = User::factory()->create();
    $second = User::factory()->create();
    snapshotBot($first, ['quote_inventory' => '100']);
    snapshotBot($second, ['quote_inventory' => '200']);

    app(EquitySnapshotter::class)->capture();

    $snapshots = EquitySnapshot::query()->get();

    expect($snapshots)->toHaveCount(2)
        ->and($snapshots->pluck('captured_at')->map->toDateTimeString()->unique()->all())
        ->toBe(['2026-11-22 12:10:00']);
});

it('skips a user whose loaded bot collection is empty', function () {
    $user = User::factory()->create();

    $snapshotter = new class extends EquitySnapshotter
    {
        public function snapshot(User $user, CarbonImmutable $capturedAt): void
        {
            $this->snapshotUser($user, $capturedAt);
        }
    };

    $snapshotter->snapshot($user->load('bots'), CarbonImmutable::parse('2026-11-22 12:05:00'));

    expect(EquitySnapshot::query()->count())->toBe(0);
});
