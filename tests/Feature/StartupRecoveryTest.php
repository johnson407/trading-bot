<?php

use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('reconciles running bots before the scheduler starts', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->toContain('bots:reconcile')
        ->and($compose)->toContain('schedule:work');
});

it('allows slow live ticks before marking a bot stale', function () {
    expect(config('trading.tick.stale_after_seconds'))->toBe(60);
});

it('reuses exchange clients per exchange and credential', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $first = ExchangeCredential::factory()->for($user)->create(['exchange' => 'binance']);
    $second = ExchangeCredential::factory()->for($otherUser)->create(['exchange' => 'binance']);

    $botA = Bot::factory()->live()->create(['exchange' => 'binance', 'exchange_credential_id' => $first->id]);
    $botB = Bot::factory()->live()->create(['exchange' => 'binance', 'exchange_credential_id' => $first->id]);
    $botC = Bot::factory()->live()->create(['exchange' => 'binance', 'exchange_credential_id' => $second->id]);
    $paperA = Bot::factory()->create(['exchange' => 'binance']);
    $paperB = Bot::factory()->create(['exchange' => 'binance']);

    $factory = new ExchangeClientFactory;

    expect($factory->forBot($botA))->toBe($factory->forBot($botB))
        ->and($factory->forBot($botA))->not->toBe($factory->forBot($botC))
        ->and($factory->forBot($paperA))->toBeInstanceOf(PaperExchangeClient::class)
        ->and($factory->forBot($paperA))->toBe($factory->forBot($paperB));
});

it('does not queue a second tick while the dispatch guard is open', function () {
    Carbon::setTestNow('2026-01-01 00:00:00');
    Cache::flush();
    Queue::fake();

    $bot = Bot::factory()->running()->create([
        'last_tick_at' => now()->subSeconds(10),
        'tick_interval_seconds' => 5,
    ]);

    $this->artisan('bots:dispatch')->assertSuccessful();
    $this->artisan('bots:dispatch')->assertSuccessful();

    Queue::assertPushed(\App\Jobs\RunBotTick::class, 1);

    Cache::flush();
    (new UniqueLock(Cache::store()))->release(new \App\Jobs\RunBotTick($bot->id));
    $bot->forceFill(['last_tick_at' => now()->subSeconds(10)])->save();

    $this->artisan('bots:dispatch')->assertSuccessful();

    Queue::assertPushed(\App\Jobs\RunBotTick::class, 2);

    Carbon::setTestNow();
});
