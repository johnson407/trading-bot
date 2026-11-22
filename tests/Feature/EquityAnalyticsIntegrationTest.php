<?php

use App\Domain\Analytics\EquitySnapshotter;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('serves captured account history on the dashboard and isolates other owners', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'quote_inventory' => '1000',
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'settings' => ['mark_price' => '110'],
    ]);

    app(EquitySnapshotter::class)->capture();

    $bot->forceFill(['settings' => ['mark_price' => '120']])->save();
    $this->travelTo(Carbon::parse('2026-11-22 12:05:00'));

    app(EquitySnapshotter::class)->capture();

    $this->actingAs($user)
        ->get('/dashboard?range=30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('equityHistory.range_days', 30)
            ->where('equityHistory.has_history', true)
            ->where('equityHistory.collecting', false)
            ->has('equityHistory.points', 2)
            ->where('equityHistory.points.0.equity', '1110.00000000')
            ->where('equityHistory.points.1.equity', '1120.00000000')
            ->where('equityHistory.metrics.period_return', '0.90')
            ->where('equityHistory.metrics.peak_equity', '1120.00000000')
            ->where('equityHistory.metrics.max_drawdown', '0.00'));

    $other = User::factory()->create();

    $this->actingAs($other)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('equityHistory.has_history', false)
            ->has('equityHistory.points', 0)
            ->where('equityHistory.metrics', null));
});
