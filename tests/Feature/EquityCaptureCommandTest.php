<?php

use App\Models\Bot;
use App\Models\EquitySnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('captures one bucket row per owner and updates it on a duplicate run', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:07:30'));

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'quote_inventory' => '100',
        'base_inventory' => '1',
        'average_entry_price' => '10',
        'settings' => ['mark_price' => '20'],
    ]);

    $this->artisan('analytics:capture-equity')
        ->expectsOutput('Captured equity for 1 account(s).')
        ->assertSuccessful();

    $snapshot = EquitySnapshot::query()->sole();

    expect($snapshot->captured_at->toDateTimeString())->toBe('2026-11-22 12:05:00')
        ->and($snapshot->equity)->toBe('120.00000000');

    $bot->forceFill([
        'quote_inventory' => '200',
        'settings' => ['mark_price' => '30'],
    ])->save();

    $this->artisan('analytics:capture-equity')
        ->expectsOutput('Captured equity for 1 account(s).')
        ->assertSuccessful();

    expect(EquitySnapshot::query()->count())->toBe(1)
        ->and(EquitySnapshot::query()->sole()->equity)->toBe('230.00000000');
});

it('reports zero accounts when no user has a bot', function () {
    $this->artisan('analytics:capture-equity')
        ->expectsOutput('Captured equity for 0 account(s).')
        ->assertSuccessful();

    expect(EquitySnapshot::query()->count())->toBe(0);
});
