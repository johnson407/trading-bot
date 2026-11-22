<?php

use App\Actions\ActivateKillSwitch;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\DailyLossMonitor;
use App\Jobs\SendAlertEmail;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\RiskLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

it('delivers a daily-loss alert end to end', function () {
    Queue::fake();

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->create(['realized_pnl' => '-150']);

    RiskLimit::factory()->forBot($bot)->create([
        'daily_loss_limit' => '100',
        'day_start' => now('UTC')->startOfDay(),
        'day_start_realized_pnl' => '0',
    ]);

    RiskLimit::factory()->create([
        'scope' => 'user',
        'user_id' => $user->id,
        'bot_id' => null,
        'daily_loss_limit' => '1000',
        'day_start' => now('UTC')->startOfDay(),
        'day_start_realized_pnl' => '0',
    ]);

    app(DailyLossMonitor::class)->evaluate($bot);

    $alert = Alert::query()->first();

    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($alert)->not->toBeNull()
        ->and($alert->user_id)->toBe($user->id)
        ->and($alert->type)->toBe('risk.daily_loss_limit');

    Queue::assertPushed(SendAlertEmail::class);

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.unread_alerts', 1)
            ->where('alerts.data.0.id', $alert->id)
            ->etc());

    $this->actingAs($user)
        ->post(route('alerts.read', $alert->id))
        ->assertRedirect();

    expect($alert->fresh()->read_at)->not->toBeNull();

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.unread_alerts', 0)
            ->etc());
});

it('creates one account-level kill-switch alert visible only to the owner', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $other = User::factory()->create();

    Bot::factory()->for($owner)->running()->count(2)->create();

    app(ActivateKillSwitch::class)->handle($owner);

    $alert = Alert::query()->first();

    expect(Alert::query()->count())->toBe(1)
        ->and($alert)->not->toBeNull()
        ->and($alert->bot_id)->toBeNull()
        ->and($alert->user_id)->toBe($owner->id)
        ->and($alert->type)->toBe('kill_switch.activated');

    $this->actingAs($owner)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts.data', 1)
            ->where('alerts.data.0.id', $alert->id)
            ->etc());

    $this->actingAs($other)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts.data', 0)
            ->etc());
});
