<?php

use App\Domain\Bot\BalanceSynchronizer;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function sharedAccountBots(): array
{
    config(['trading.live_enabled' => true]);

    $user = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($user)->create();

    $first = Bot::factory()->for($user)->running()->live()->create([
        'exchange_credential_id' => $credential->id,
        'quote_inventory' => '500',
        'base_inventory' => '2',
    ]);
    $second = Bot::factory()->for($user)->running()->live()->create([
        'exchange_credential_id' => $credential->id,
        'quote_inventory' => '250',
        'base_inventory' => '1',
    ]);

    $fake = new FakeExchangeClient(quoteBalance: '9999', baseBalance: '99');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, $fake));

    return [$first, $second, $fake];
}

it('skips balance sync when several live bots share one credential', function () {
    [$first, $second, $fake] = sharedAccountBots();

    $result = app(BalanceSynchronizer::class)->sync($first);

    expect($result)->toBeNull()
        ->and($fake->balanceCalls)->toBe(0)
        ->and($first->fresh()->quote_inventory)->toBe('500.0000000000000000')
        ->and($first->fresh()->base_inventory)->toBe('2.0000000000000000')
        ->and($second->fresh()->quote_inventory)->toBe('250.0000000000000000')
        ->and($first->activityLogs()->where('type', 'bot.balance_sync_skipped')->count())->toBe(1);
});

it('still syncs when a credential belongs to a single live bot', function () {
    config(['trading.live_enabled' => true]);

    $user = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($user)->create();
    $bot = Bot::factory()->for($user)->live()->create([
        'exchange_credential_id' => $credential->id,
        'quote_inventory' => '500',
    ]);
    Bot::factory()->for($user)->create([
        'exchange_credential_id' => $credential->id,
    ]);

    $fake = new FakeExchangeClient(quoteBalance: '9999', baseBalance: '3');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, $fake));

    $result = app(BalanceSynchronizer::class)->sync($bot);

    expect($result)->toBe(['base' => '3', 'quote' => '9999'])
        ->and($bot->fresh()->quote_inventory)->toBe('9999.0000000000000000');
});

it('does not pause a shared-account bot on the reconciliation balance check', function () {
    [$first] = sharedAccountBots();

    $result = app(\App\Domain\Bot\BotReconciler::class)->reconcile($first);

    expect($result->ok())->toBeTrue()
        ->and($first->fresh()->status->value)->toBe('running')
        ->and($first->riskEvents()->where('type', 'risk.reconciliation_conflict')->count())->toBe(0);
});

it('records the shared-account skip at most once per bot per day', function () {
    [$first, $second] = sharedAccountBots();

    app(BalanceSynchronizer::class)->sync($first);
    app(BalanceSynchronizer::class)->sync($first);

    expect(ActivityLog::where('bot_id', $first->id)->where('type', 'bot.balance_sync_skipped')->count())->toBe(1);
});
