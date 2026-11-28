<?php

use App\Domain\Alerts\AlertGrouping;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function groupAlert(User $user, array $attributes = []): Alert
{
    return Alert::factory()->create(array_merge([
        'user_id' => $user->id,
        'bot_id' => null,
        'type' => 'risk.position_limit',
        'severity' => 'warning',
        'title' => 'Alert title',
        'message' => 'Alert message',
        'context' => [],
        'read_at' => null,
        'created_at' => now(),
    ], $attributes));
}

it('groups alerts over the last 30 days with counts and highest severity', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['name' => 'Grid bot']);

    groupAlert($user, [
        'bot_id' => $bot->id,
        'severity' => 'warning',
        'message' => 'First',
        'created_at' => now()->subDays(10),
        'read_at' => now(),
    ]);
    groupAlert($user, [
        'bot_id' => $bot->id,
        'severity' => 'critical',
        'message' => 'Second',
        'created_at' => now()->subDay(),
    ]);
    groupAlert($user, [
        'bot_id' => $bot->id,
        'severity' => 'info',
        'message' => 'Third',
        'created_at' => now()->subHours(2),
    ]);
    groupAlert($user, [
        'type' => 'exchange.failure',
        'message' => 'Other group',
        'created_at' => now()->subDay(),
    ]);
    groupAlert($user, [
        'bot_id' => $bot->id,
        'message' => 'Too old',
        'created_at' => now()->subDays(31),
    ]);

    $groups = app(AlertGrouping::class)->groups($user);

    expect($groups->total())->toBe(2);

    $group = collect($groups->items())->firstWhere('type', 'risk.position_limit');

    expect($group)->not->toBeNull()
        ->and($group['total_count'])->toBe(3)
        ->and($group['unread_count'])->toBe(2)
        ->and($group['highest_severity'])->toBe('critical')
        ->and($group['latest_message'])->toBe('Third')
        ->and($group['bot']['name'])->toBe('Grid bot')
        ->and($group['bot']['archived'])->toBeFalse()
        ->and($group['key'])->toBe('risk.position_limit|'.$bot->id);
});

it('paginates groups 20 at a time newest first', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 25; $i++) {
        groupAlert($user, [
            'type' => 'type.'.$i,
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $groups = app(AlertGrouping::class)->groups($user);

    expect($groups->total())->toBe(25)
        ->and($groups->perPage())->toBe(20)
        ->and($groups->count())->toBe(20)
        ->and($groups->items()[0]['type'])->toBe('type.1')
        ->and($groups->items()[19]['type'])->toBe('type.20');
});

it('applies the bot filter to grouped rows', function () {
    $user = User::factory()->create();
    $first = Bot::factory()->for($user)->create();
    $second = Bot::factory()->for($user)->create();

    groupAlert($user, ['bot_id' => $first->id]);
    groupAlert($user, ['bot_id' => $second->id]);

    $groups = app(AlertGrouping::class)->groups($user, $first->id);

    expect($groups->total())->toBe(1)
        ->and($groups->items()[0]['bot_id'])->toBe($first->id);
});

it('includes archived and account groups', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['name' => 'Archived bot']);

    groupAlert($user, ['bot_id' => $bot->id]);
    groupAlert($user, ['bot_id' => null, 'type' => 'account.alert']);

    $bot->delete();

    $items = collect(app(AlertGrouping::class)->groups($user)->items());
    $archived = $items->firstWhere('bot_id', $bot->id);
    $account = $items->firstWhere('type', 'account.alert');

    expect($archived['bot']['archived'])->toBeTrue()
        ->and($account['bot'])->toBeNull()
        ->and($account['key'])->toBe('account.alert|account');
});

it('returns at most five recent occurrences per group', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    for ($i = 1; $i <= 7; $i++) {
        groupAlert($user, [
            'bot_id' => $bot->id,
            'message' => 'Message '.$i,
            'created_at' => now()->subMinutes(8 - $i),
        ]);
    }

    $grouping = app(AlertGrouping::class);
    $groups = $grouping->groups($user);
    $recent = $grouping->recentOccurrences($user, $groups->items());
    $key = $groups->items()[0]['key'];

    expect($recent[$key])->toHaveCount(5)
        ->and($recent[$key][0]['message'])->toBe('Message 7')
        ->and($recent[$key][4]['message'])->toBe('Message 3');
});

it('marks only unread alerts up to the group timestamp', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $otherBot = Bot::factory()->for($other)->create();

    $first = groupAlert($user, [
        'bot_id' => $bot->id,
        'message' => 'First',
        'created_at' => now()->subHours(3),
    ]);
    $second = groupAlert($user, [
        'bot_id' => $bot->id,
        'message' => 'Second',
        'created_at' => now()->subHours(2),
    ]);
    $third = groupAlert($user, [
        'bot_id' => $bot->id,
        'message' => 'Third',
        'created_at' => now()->subHour(),
    ]);
    $otherAlert = groupAlert($other, [
        'bot_id' => $otherBot->id,
        'created_at' => now()->subHours(2),
    ]);

    $count = app(AlertGrouping::class)->markRead(
        $user,
        'risk.position_limit',
        $bot->id,
        now()->subHours(2),
    );

    expect($count)->toBe(2)
        ->and($first->fresh()->read_at)->not->toBeNull()
        ->and($second->fresh()->read_at)->not->toBeNull()
        ->and($third->fresh()->read_at)->toBeNull()
        ->and($otherAlert->fresh()->read_at)->toBeNull();
});

it('marks account-level alerts read up to the group timestamp', function () {
    $user = User::factory()->create();
    $first = groupAlert($user, [
        'bot_id' => null,
        'created_at' => now()->subMinutes(2),
    ]);
    $second = groupAlert($user, [
        'bot_id' => null,
        'created_at' => now()->subMinute(),
    ]);
    $later = groupAlert($user, [
        'bot_id' => null,
        'created_at' => now(),
    ]);

    $count = app(AlertGrouping::class)->markRead(
        $user,
        'risk.position_limit',
        null,
        $second->created_at,
    );

    expect($count)->toBe(2)
        ->and($first->fresh()->read_at)->not->toBeNull()
        ->and($second->fresh()->read_at)->not->toBeNull()
        ->and($later->fresh()->read_at)->toBeNull();
});

it('orders tied groups by bot id deterministically', function () {
    $user = User::factory()->create();
    $first = Bot::factory()->for($user)->create();
    $second = Bot::factory()->for($user)->create();
    $latest = now()->subMinute();

    groupAlert($user, ['bot_id' => $first->id, 'created_at' => $latest]);
    groupAlert($user, ['bot_id' => $second->id, 'created_at' => $latest]);

    $groups = app(AlertGrouping::class)->groups($user);

    expect(collect($groups->items())->pluck('bot_id')->all())->toBe([
        max($first->id, $second->id),
        min($first->id, $second->id),
    ]);
});

it('keeps group keys stable for account and bot alerts', function () {
    $grouping = app(AlertGrouping::class);

    expect($grouping->groupKey('risk.position_limit', null))->toBe('risk.position_limit|account')
        ->and($grouping->groupKey('risk.position_limit', 12))->toBe('risk.position_limit|12');
});
