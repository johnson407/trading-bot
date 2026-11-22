<?php

use App\Domain\Bot\Enums\BotStatus;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates protective exits for a running bot and records the change', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->create();

    $this->actingAs($user)
        ->patch(route('bots.exit-guard.update', $bot), [
            'stop_loss_percent' => '5',
            'take_profit_percent' => '12.5',
            'range_low' => '80000',
            'range_high' => '120000',
        ])
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHas('status', 'Protective exits updated.');

    $bot->refresh();

    expect($bot->status)->toBe(BotStatus::Running)
        ->and($bot->stop_loss_percent)->toBe('5.0000')
        ->and($bot->take_profit_percent)->toBe('12.5000')
        ->and($bot->range_low)->toBe('80000.00000000')
        ->and($bot->range_high)->toBe('120000.00000000');

    $activity = ActivityLog::query()->where('type', 'bot.exit_guard_updated')->sole();

    expect($activity->bot_id)->toBe($bot->id)
        ->and($activity->user_id)->toBe($user->id)
        ->and($activity->context['before']['stop_loss_percent'])->toBeNull()
        ->and($activity->context['after']['stop_loss_percent'])->toBe('5.0000');
});

it('disables rules when the fields are blank', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->withExitRules()->create();

    $this->actingAs($user)
        ->patch(route('bots.exit-guard.update', $bot), [
            'stop_loss_percent' => '',
            'take_profit_percent' => '',
            'range_low' => '',
            'range_high' => '',
        ])
        ->assertRedirect(route('bots.show', $bot));

    $bot->refresh();

    expect($bot->stop_loss_percent)->toBeNull()
        ->and($bot->take_profit_percent)->toBeNull()
        ->and($bot->range_low)->toBeNull()
        ->and($bot->range_high)->toBeNull();
});

it('disables only the cleared rule and keeps the others', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->withExitRules()->create();

    $this->actingAs($user)
        ->patch(route('bots.exit-guard.update', $bot), [
            'stop_loss_percent' => '',
        ])
        ->assertRedirect(route('bots.show', $bot));

    $bot->refresh();

    expect($bot->stop_loss_percent)->toBeNull()
        ->and($bot->take_profit_percent)->toBe('9.0000')
        ->and($bot->range_low)->toBe('80000.00000000')
        ->and($bot->range_high)->toBe('120000.00000000');
});

it('keeps every stored rule when an update is invalid', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->withExitRules()->create();

    $this->actingAs($user)
        ->patch(route('bots.exit-guard.update', $bot), [
            'stop_loss_percent' => '150',
        ])
        ->assertSessionHasErrors('stop_loss_percent');

    $bot->refresh();

    expect($bot->stop_loss_percent)->toBe('4.0000')
        ->and($bot->take_profit_percent)->toBe('9.0000')
        ->and($bot->range_low)->toBe('80000.00000000')
        ->and($bot->range_high)->toBe('120000.00000000');
});

it('validates a partial range update against the stored bound', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->withExitRules()->create();

    $this->actingAs($user)
        ->patch(route('bots.exit-guard.update', $bot), [
            'range_low' => '130000',
        ])
        ->assertSessionHasErrors('range_high');

    expect($bot->fresh()->range_low)->toBe('80000.00000000');
});

it('rejects invalid protective exit values', function (array $payload, string $errorField) {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch(route('bots.exit-guard.update', $bot), $payload)
        ->assertSessionHasErrors($errorField);

    expect($bot->fresh()->stop_loss_percent)->toBeNull()
        ->and($bot->fresh()->take_profit_percent)->toBeNull()
        ->and($bot->fresh()->range_low)->toBeNull()
        ->and($bot->fresh()->range_high)->toBeNull();
})->with([
    'zero stop loss' => [['stop_loss_percent' => '0'], 'stop_loss_percent'],
    'full stop loss' => [['stop_loss_percent' => '100'], 'stop_loss_percent'],
    'excess stop loss precision' => [['stop_loss_percent' => '5.12345'], 'stop_loss_percent'],
    'zero take profit' => [['take_profit_percent' => '0'], 'take_profit_percent'],
    'full take profit' => [['take_profit_percent' => '100'], 'take_profit_percent'],
    'over full take profit' => [['take_profit_percent' => '150'], 'take_profit_percent'],
    'excess take profit precision' => [['take_profit_percent' => '9.12345'], 'take_profit_percent'],
    'negative range low' => [['range_low' => '-1'], 'range_low'],
    'range low above column range' => [['range_low' => '100000000'], 'range_low'],
    'range high below low' => [['range_low' => '100', 'range_high' => '100'], 'range_high'],
]);

it('forbids another user from changing protective exits', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create();

    $this->actingAs($other)
        ->patch(route('bots.exit-guard.update', $bot), ['stop_loss_percent' => '5'])
        ->assertForbidden();
});

it('requires authentication', function () {
    $bot = Bot::factory()->create();

    $this->patch(route('bots.exit-guard.update', $bot), ['stop_loss_percent' => '5'])
        ->assertRedirect(route('login'));
});
