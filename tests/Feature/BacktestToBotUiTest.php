<?php

use App\Models\BacktestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders source mode for a completed usable run', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create(['name' => 'Momentum']);

    $this->actingAs($user)
        ->get(route('bots.create', ['source_backtest_run_id' => $run->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Create')
            ->where('sourceRun.id', $run->id)
            ->where('sourceRun.name', 'Momentum')
            ->where('sourceRun.symbol', 'BTC/USDT')
            ->where('initial.source_backtest_run_id', $run->id)
            ->where('initial.name', 'Momentum paper')
            ->where('initial.mode', 'paper')
            ->where('initial.capital_allocation', '1000')
            ->where('initial.fee_rate', '0.001')
            ->where('initial.level_count', 2)
            ->where('initial.stop_loss_percent', '4'));
});

it('returns not found for another user source run', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $run = BacktestRun::factory()->for($other)->completedSource()->create();

    $this->actingAs($user)
        ->get(route('bots.create', ['source_backtest_run_id' => $run->id]))
        ->assertNotFound();
});

it('returns not found for a pending source run', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('bots.create', ['source_backtest_run_id' => $run->id]))
        ->assertNotFound();
});

it('returns not found when the source snapshot cannot create a bot', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();
    $snapshot = $run->config_snapshot;
    unset($snapshot['strategy']['configuration']);

    $run = BacktestRun::factory()->for($user)->completedSource()->create([
        'config_snapshot' => $snapshot,
    ]);

    $this->actingAs($user)
        ->get(route('bots.create', ['source_backtest_run_id' => $run->id]))
        ->assertNotFound();
});

it('returns not found when the source exchange is no longer allowed', function () {
    config(['trading.exchanges' => ['kraken']]);

    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();

    $this->actingAs($user)
        ->get(route('bots.create', ['source_backtest_run_id' => $run->id]))
        ->assertNotFound();
});

it('renders regular creation without source props', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('bots.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Create')
            ->where('sourceRun', null)
            ->where('initial', null));
});

it('keeps source mode controls in the create page', function () {
    $vue = file_get_contents(resource_path('js/Pages/Bots/Create.vue'));

    expect($vue)
        ->toContain('source_backtest_run_id')
        ->toContain('sourceMode')
        ->toContain('Paper only')
        ->toContain('will not start automatically')
        ->toContain('aria-readonly="true"')
        ->toContain('Stop loss')
        ->toContain(':readonly="sourceMode"');
});
