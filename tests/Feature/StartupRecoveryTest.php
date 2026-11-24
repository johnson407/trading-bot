<?php

use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not reconcile bots before the scheduler starts', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->not->toContain('bots:reconcile')
        ->and($compose)->toContain('schedule:work');
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
