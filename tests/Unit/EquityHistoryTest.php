<?php

use App\Domain\Analytics\EquityHistory;
use App\Models\EquitySnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function historyPoint(User $user, Carbon $at, string $equity): EquitySnapshot
{
    return EquitySnapshot::factory()->for($user)->at($at->toDateTimeString())->create([
        'equity' => $equity,
    ]);
}

it('returns chronological points and full-range metrics', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    historyPoint($user, now()->subDays(2), '100');
    historyPoint($user, now()->subDay(), '120');
    historyPoint($user, now(), '90');

    $history = app(EquityHistory::class)->forUser($user, 7);

    expect(array_column($history['points'], 'equity'))->toBe([
        '100.00000000',
        '120.00000000',
        '90.00000000',
    ])
        ->and($history['metrics'])->toMatchArray([
            'period_return' => '-10.00',
            'peak_equity' => '120.00000000',
            'max_drawdown' => '25.00',
        ])
        ->and($history['range_days'])->toBe(7)
        ->and($history['available_ranges'])->toBe([7, 30, 90])
        ->and($history['has_history'])->toBeTrue()
        ->and($history['collecting'])->toBeFalse();
});

it('excludes snapshots older than the requested range', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    historyPoint($user, now()->subDays(10), '50');
    historyPoint($user, now()->subDays(2), '100');
    historyPoint($user, now(), '110');

    $history = app(EquityHistory::class)->forUser($user, 7);

    expect($history['points'])->toHaveCount(2)
        ->and($history['metrics']['period_return'])->toBe('10.00')
        ->and($history['metrics']['peak_equity'])->toBe('110.00000000');
});

it('downsamples the chart while computing metrics from the full range', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));
    config(['analytics.chart_points' => 5]);

    $user = User::factory()->create();
    $values = ['100', '110', '90', '130', '120', '140', '80', '150', '160', '100'];

    foreach ($values as $index => $value) {
        historyPoint($user, now()->subDays(9 - $index), $value);
    }

    $history = app(EquityHistory::class)->forUser($user, 30);

    expect($history['points'])->toHaveCount(5)
        ->and($history['points'][0]['equity'])->toBe('100.00000000')
        ->and($history['points'][4]['equity'])->toBe('100.00000000')
        ->and($history['metrics']['peak_equity'])->toBe('160.00000000')
        ->and($history['metrics']['max_drawdown'])->toBe('42.86');
});

it('reports a single point as collecting without metrics', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    historyPoint($user, now(), '100');

    $history = app(EquityHistory::class)->forUser($user, 30);

    expect($history['points'])->toHaveCount(1)
        ->and($history['metrics'])->toBeNull()
        ->and($history['has_history'])->toBeTrue()
        ->and($history['collecting'])->toBeTrue();
});

it('reports no history for an empty range', function () {
    $user = User::factory()->create();

    $history = app(EquityHistory::class)->forUser($user, 7);

    expect($history['points'])->toBe([])
        ->and($history['metrics'])->toBeNull()
        ->and($history['has_history'])->toBeFalse()
        ->and($history['collecting'])->toBeTrue();
});

it('never includes another owner snapshots', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $other = User::factory()->create();

    historyPoint($user, now()->subDay(), '100');
    historyPoint($user, now(), '110');
    historyPoint($other, now(), '999999');

    $history = app(EquityHistory::class)->forUser($user, 7);

    expect($history['points'])->toHaveCount(2)
        ->and(array_column($history['points'], 'equity'))->not->toContain('999999.00000000');
});

it('omits return when the start equity is not positive', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    historyPoint($user, now()->subDay(), '0');
    historyPoint($user, now(), '10');

    $history = app(EquityHistory::class)->forUser($user, 7);

    expect($history['metrics']['period_return'])->toBeNull()
        ->and($history['metrics']['peak_equity'])->toBe('10.00000000')
        ->and($history['metrics']['max_drawdown'])->toBe('0.00');
});
