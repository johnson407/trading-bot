<?php

use App\Jobs\RunBotTick;
use App\Models\Bot;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-01-01 00:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches a bot only when its interval has elapsed', function () {
    Queue::fake();
    $bot = Bot::factory()->running()->create([
        'last_tick_at' => null,
        'tick_interval_seconds' => 1,
    ]);

    $this->artisan('bots:dispatch')->assertSuccessful();
    $this->artisan('bots:dispatch')->assertSuccessful();

    Queue::assertPushed(RunBotTick::class, 1);

    (new UniqueLock(Cache::store()))->release(new RunBotTick($bot->id));
    $bot->forceFill(['last_tick_at' => now()->subSeconds(2)])->save();
    Carbon::setTestNow(now()->addSecond());

    $this->artisan('bots:dispatch')->assertSuccessful();

    Queue::assertPushed(RunBotTick::class, 2);
});

it('does not grow the queue when dispatching repeatedly inside an interval', function () {
    Queue::fake();
    $bot = Bot::factory()->running()->create([
        'last_tick_at' => now(),
        'tick_interval_seconds' => 5,
    ]);

    for ($i = 0; $i < 10; $i++) {
        $this->artisan('bots:dispatch')->assertSuccessful();
    }

    Queue::assertPushed(RunBotTick::class, 0);
});

it('skips paused and stopped bots', function () {
    Queue::fake();
    Bot::factory()->create(['status' => 'paused', 'last_tick_at' => null]);
    Bot::factory()->create(['status' => 'stopped', 'last_tick_at' => null]);

    $this->artisan('bots:dispatch')->assertSuccessful();

    Queue::assertNotPushed(RunBotTick::class);
});

it('uses a unique id and an overlap lock for each bot', function () {
    $job = new RunBotTick(42);
    $middleware = $job->middleware();

    expect($job->uniqueId())->toBe('bot-tick:42')
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});
