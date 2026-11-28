<?php

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('allows the owner to update idle and stopped bots', function () {
    $user = User::factory()->create();

    foreach (['idle', 'stopped'] as $status) {
        $bot = Bot::factory()->for($user)->create(['status' => $status]);

        expect(Gate::forUser($user)->allows('update', $bot))->toBeTrue();
    }
});

it('allows running paper bots but forbids live running and paused bots', function () {
    $user = User::factory()->create();

    $bot = Bot::factory()->for($user)->create(['status' => 'running', 'mode' => 'paper']);
    expect(Gate::forUser($user)->allows('update', $bot))->toBeTrue();

    $bot->forceFill(['mode' => 'live'])->save();
    expect(Gate::forUser($user)->allows('update', $bot))->toBeFalse();

    $bot->forceFill(['status' => 'paused', 'mode' => 'paper'])->save();
    expect(Gate::forUser($user)->allows('update', $bot))->toBeFalse();
});

it('forbids another user from updating a bot', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create(['status' => 'idle']);

    expect(Gate::forUser($other)->allows('update', $bot))->toBeFalse();
});

it('registers the edit and update routes behind authentication', function () {
    $bot = Bot::factory()->create();

    expect(route('bots.edit', $bot, absolute: false))->toBe("/bots/{$bot->id}/edit")
        ->and(route('bots.update', $bot, absolute: false))->toBe("/bots/{$bot->id}");
});
