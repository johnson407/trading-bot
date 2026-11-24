<?php

use App\Domain\Activity\ActivityRecorder;
use App\Models\Bot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects engine-reserved activity types from user-facing writes', function (string $type) {
    $bot = Bot::factory()->create();

    app(ActivityRecorder::class)->recordUser($type, 'Should not be recorded.', $bot);
})->with([
    'bot.stale',
    'bot.balance_synced',
    'bot.exit_guard_triggered',
    'order.filled',
    'orders.synced',
])->throws(InvalidArgumentException::class);

it('accepts the user-facing activity types', function () {
    $bot = Bot::factory()->create();

    app(ActivityRecorder::class)->recordUser('bot.paused', 'Bot paused.', $bot);

    $this->assertDatabaseHas('activity_logs', [
        'bot_id' => $bot->id,
        'type' => 'bot.paused',
    ]);
});
