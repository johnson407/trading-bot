<?php

use App\Domain\Bot\BotHealth;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function healthRows(Bot $bot, array $heartbeat = [], ?CarbonImmutable $now = null): array
{
    $now ??= CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');

    return (new BotHealth)->forBots(new Collection([$bot]), $heartbeat, $now);
}

it('reports an engine-paused bot with its reason and allows resume', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $bot = Bot::factory()->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
        'last_error' => 'No successful tick within the stale-tick threshold.',
        'last_successful_tick_at' => $now->subMinutes(5),
        'last_tick_at' => $now->subMinutes(5),
    ]);

    $rows = healthRows($bot, [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ], $now);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($bot->id)
        ->and($rows[0]['status'])->toBe('paused')
        ->and($rows[0]['desired_status'])->toBe('running')
        ->and($rows[0]['issues'])->toBe(['paused_by_engine'])
        ->and($rows[0]['reason'])->toBe('No successful tick within the stale-tick threshold.')
        ->and($rows[0]['can_resume'])->toBeTrue();
});

it('uses a generic reason when an engine-paused bot has no last error', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $bot = Bot::factory()->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
        'last_error' => '   ',
        'last_successful_tick_at' => $now,
    ]);

    $rows = healthRows($bot, [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ], $now);

    expect($rows[0]['last_error'])->toBeNull()
        ->and($rows[0]['reason'])->toBe('Paused by the engine. Resume when the cause is resolved.');
});

it('marks a running bot stale only after the stale threshold', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $heartbeat = [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ];

    $stale = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 60,
        'last_successful_tick_at' => $now->subSeconds(181),
        'last_tick_at' => $now->subSeconds(181),
    ]);
    $boundary = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 60,
        'last_successful_tick_at' => $now->subSeconds(180),
        'last_tick_at' => $now->subSeconds(180),
    ]);

    $staleRows = healthRows($stale, $heartbeat, $now);
    $boundaryRows = healthRows($boundary, $heartbeat, $now);

    expect($staleRows)->toHaveCount(1)
        ->and($staleRows[0]['issues'])->toBe(['stale'])
        ->and($staleRows[0]['reason'])->toBe('No successful tick within the stale threshold.')
        ->and($staleRows[0]['can_resume'])->toBeFalse()
        ->and($boundaryRows)->toBeEmpty();
});

it('uses the greater of the floor and interval plus thirty seconds', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $heartbeat = [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ];

    $withinGrace = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 300,
        'last_successful_tick_at' => $now->subSeconds(200),
    ]);
    $stale = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 300,
        'last_successful_tick_at' => $now->subSeconds(331),
    ]);

    expect(healthRows($withinGrace, $heartbeat, $now))->toBeEmpty()
        ->and(healthRows($stale, $heartbeat, $now))->toHaveCount(1);
});

it('does not report stale during engine restart grace', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $bot = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 60,
        'last_successful_tick_at' => $now->subSeconds(1000),
    ]);

    $rows = healthRows($bot, [
        'available' => true,
        'started_at' => $now->subSeconds(60)->toIso8601String(),
    ], $now);

    expect($rows)->toBeEmpty();
});

it('does not report stale at the exact engine uptime boundary', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $bot = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 60,
        'last_successful_tick_at' => $now->subSeconds(1000),
    ]);

    $rows = healthRows($bot, [
        'available' => true,
        'started_at' => $now->subSeconds(180)->toIso8601String(),
    ], $now);

    expect($rows)->toBeEmpty();
});

it('rejects permissive non-RFC3339 engine start times', function (string $startedAt) {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $bot = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 60,
        'last_successful_tick_at' => $now->subSeconds(1000),
    ]);

    $rows = healthRows($bot, [
        'available' => true,
        'started_at' => $startedAt,
    ], $now);

    expect($rows)->toBeEmpty();
})->with([
    'relative' => 'yesterday',
    'date only' => '2026-11-26',
    'missing offset' => '2026-11-26T11:00:00',
]);

it('uses last tick and created at as stale fallbacks', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $heartbeat = [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ];

    $lastTickFallback = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 60,
        'last_successful_tick_at' => null,
        'last_tick_at' => $now->subSeconds(181),
        'created_at' => $now->subSeconds(1000),
    ]);
    $createdAtFallback = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'tick_interval_seconds' => 60,
        'last_successful_tick_at' => null,
        'last_tick_at' => null,
        'created_at' => $now->subSeconds(181),
    ]);

    expect(healthRows($lastTickFallback, $heartbeat, $now))->toHaveCount(1)
        ->and(healthRows($createdAtFallback, $heartbeat, $now))->toHaveCount(1);
});

it('does not report stale without a fresh heartbeat or engine start time', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $bot = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'last_successful_tick_at' => $now->subSeconds(1000),
    ]);

    expect(healthRows($bot, ['available' => false], $now))->toBeEmpty()
        ->and(healthRows($bot, ['available' => true, 'started_at' => null], $now))->toBeEmpty()
        ->and(healthRows($bot, ['available' => true, 'started_at' => 'not-a-date'], $now))->toBeEmpty();
});

it('reports error states and running bots with a non-blank last error', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $heartbeat = [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ];

    $error = Bot::factory()->create([
        'status' => BotStatus::Error,
        'desired_status' => BotStatus::Running,
        'last_error' => 'Exchange rejected the order.',
        'last_successful_tick_at' => $now,
    ]);
    $runningWithError = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'last_error' => 'Balance check failed.',
        'last_successful_tick_at' => $now,
    ]);
    $runningWithBlankError = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'last_error' => '  ',
        'last_successful_tick_at' => $now,
    ]);

    $errorRows = healthRows($error, $heartbeat, $now);
    $runningRows = healthRows($runningWithError, $heartbeat, $now);

    expect($errorRows)->toHaveCount(1)
        ->and($errorRows[0]['issues'])->toBe(['error'])
        ->and($errorRows[0]['reason'])->toBe('Exchange rejected the order.')
        ->and($errorRows[0]['can_resume'])->toBeFalse()
        ->and($runningRows)->toHaveCount(1)
        ->and($runningRows[0]['issues'])->toBe(['error'])
        ->and(healthRows($runningWithBlankError, $heartbeat, $now))->toBeEmpty();
});

it('combines stale and error issues for one running bot', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $bot = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'last_error' => 'Tick failed.',
        'last_successful_tick_at' => $now->subSeconds(500),
    ]);

    $rows = healthRows($bot, [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ], $now);

    expect($rows[0]['issues'])->toBe(['stale', 'error'])
        ->and($rows[0]['reason'])->toContain('No successful tick')
        ->and($rows[0]['reason'])->toContain('Tick failed.');
});

it('omits healthy, idle, stopped, user-paused, and archived bots', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $heartbeat = [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ];

    $healthy = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'last_successful_tick_at' => $now,
    ]);
    $idle = Bot::factory()->create(['status' => BotStatus::Idle, 'desired_status' => BotStatus::Idle]);
    $stopped = Bot::factory()->create(['status' => BotStatus::Stopped, 'desired_status' => BotStatus::Stopped]);
    $userPaused = Bot::factory()->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Paused,
        'last_successful_tick_at' => $now->subDay(),
    ]);
    $archived = Bot::factory()->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
        'deleted_at' => $now,
    ]);

    $rows = (new BotHealth)->forBots(
        new Collection([$healthy, $idle, $stopped, $userPaused, $archived]),
        $heartbeat,
        $now,
    );

    expect($rows)->toBeEmpty();
});

it('orders paused-by-engine rows before stale and error rows', function () {
    $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC');
    $heartbeat = [
        'available' => true,
        'started_at' => $now->subHour()->toIso8601String(),
    ];

    $error = Bot::factory()->create([
        'status' => BotStatus::Error,
        'desired_status' => BotStatus::Running,
        'last_error' => 'Error.',
        'last_successful_tick_at' => $now,
    ]);
    $stale = Bot::factory()->running()->create([
        'desired_status' => BotStatus::Running,
        'last_successful_tick_at' => $now->subSeconds(500),
    ]);
    $paused = Bot::factory()->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
        'last_successful_tick_at' => $now,
    ]);

    $rows = (new BotHealth)->forBots(new Collection([$error, $stale, $paused]), $heartbeat, $now);

    expect(array_column($rows, 'id'))->toBe([$paused->id, $stale->id, $error->id]);
});
