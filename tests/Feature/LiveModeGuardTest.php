<?php

use App\Actions\StartBot;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\Exceptions\InsufficientLiveBalanceException;
use App\Domain\Risk\Exceptions\LiveConfirmationRequiredException;
use App\Domain\Risk\Exceptions\LiveTradingDisabledException;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('rejects a live start when live trading is disabled', function () {
    config()->set('trading.live_enabled', false);
    $bot = Bot::factory()->live()->create();

    expect(fn () => app(StartBot::class)->handle(
        $bot,
        new LiveStartConfirmation(LiveStartConfirmation::phrase($bot)),
        new FakeExchangeClient,
    ))->toThrow(LiveTradingDisabledException::class);

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Idle);
});

it('requires confirmation text matching the bot name', function () {
    config()->set('trading.live_enabled', true);
    $bot = Bot::factory()->live()->create();

    expect(fn () => app(StartBot::class)->handle($bot, null, new FakeExchangeClient))
        ->toThrow(LiveConfirmationRequiredException::class);

    expect(fn () => app(StartBot::class)->handle(
        $bot,
        new LiveStartConfirmation('wrong confirmation'),
        new FakeExchangeClient,
    ))->toThrow(LiveConfirmationRequiredException::class);

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Idle);
});

it('starts a live bot when confirmed and the balance is sufficient', function () {
    config()->set('trading.live_enabled', true);
    $user = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($user)->create(['exchange' => 'binance']);
    $bot = Bot::factory()->for($user)->live()->create([
        'exchange_credential_id' => $credential->id,
        'capital_allocation' => '1000',
    ]);

    app(StartBot::class)->handle(
        $bot,
        new LiveStartConfirmation(LiveStartConfirmation::phrase($bot)),
        new FakeExchangeClient(quoteBalance: '1000'),
    );

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Running);
});

it('rejects a live start when the quote balance is short', function () {
    config()->set('trading.live_enabled', true);
    $bot = Bot::factory()->live()->create(['capital_allocation' => '1000']);

    expect(fn () => app(StartBot::class)->handle(
        $bot,
        new LiveStartConfirmation(LiveStartConfirmation::phrase($bot)),
        new FakeExchangeClient(quoteBalance: '999.99'),
    ))->toThrow(InsufficientLiveBalanceException::class);

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Idle);
});

it('refuses a live MAX start before any exchange balance call', function () {
    config()->set('trading.live_enabled', true);
    $bot = Bot::factory()->live()->create(['exchange' => 'max']);
    $exchange = new FakeExchangeClient(quoteBalance: '1000');

    expect(fn () => app(StartBot::class)->handle(
        $bot,
        new LiveStartConfirmation(LiveStartConfirmation::phrase($bot)),
        $exchange,
    ))->toThrow(LiveTradingDisabledException::class);

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Idle);
});

it('does not require live safeguards in paper mode', function () {
    config()->set('trading.live_enabled', false);
    $bot = Bot::factory()->create(['mode' => 'paper']);

    app(StartBot::class)->handle($bot);

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Running);
});
