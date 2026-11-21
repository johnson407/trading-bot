<?php

use App\Actions\SaveExchangeCredential;
use App\Models\ExchangeCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('stores credentials encrypted and masks them for display', function () {
    $user = User::factory()->create();

    $credential = app(SaveExchangeCredential::class)->handle(
        $user,
        'binance',
        'abcd1234efgh5678',
        'super-secret-value',
        'passphrase-value',
        testnet: true,
    );

    $raw = DB::table('exchange_credentials')->where('id', $credential->id)->first();

    expect($raw->api_key)->not->toBe('abcd1234efgh5678')
        ->and($raw->api_secret)->not->toBe('super-secret-value')
        ->and($raw->passphrase)->not->toBe('passphrase-value')
        ->and($credential->toMaskedArray()['masked_key'])->toBe('abcd****5678')
        ->and($credential->toMaskedArray())->not->toHaveKey('api_secret');
});

it('replaces an existing credential for the same user and exchange', function () {
    $user = User::factory()->create();
    $action = app(SaveExchangeCredential::class);

    $first = $action->handle($user, 'binance', 'old-key-1234', 'old-secret');
    $second = $action->handle($user, 'binance', 'new-key-5678', 'new-secret');

    expect($second->id)->toBe($first->id)
        ->and(ExchangeCredential::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($second->api_key)->toBe('new-key-5678')
        ->and($second->api_secret)->toBe('new-secret');
});

it('never writes credential secrets to logs', function () {
    Log::spy();
    $user = User::factory()->create();

    app(SaveExchangeCredential::class)->handle($user, 'binance', 'key-12345678', 'secret-12345678');

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('error');
});
