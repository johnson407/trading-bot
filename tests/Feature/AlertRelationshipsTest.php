<?php

use App\Models\Alert;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('relates alerts to their owner and bot and preserves them when the bot is deleted', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $alert = Alert::factory()->for($user)->for($bot)->create();

    expect($user->alerts->first()->is($alert))->toBeTrue()
        ->and($user->unreadAlerts->first()->is($alert))->toBeTrue()
        ->and($bot->alerts->first()->is($alert))->toBeTrue();

    $bot->delete();

    $fresh = $alert->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->bot_id)->toBeNull()
        ->and($fresh->bot)->toBeNull()
        ->and($fresh->message)->toBe($alert->message);
});

it('excludes read alerts from the unread relation', function () {
    $user = User::factory()->create();

    Alert::factory()->for($user)->create();
    Alert::factory()->for($user)->read()->create();

    expect($user->unreadAlerts()->count())->toBe(1)
        ->and($user->alerts()->count())->toBe(2);
});
