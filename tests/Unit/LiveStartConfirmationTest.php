<?php

use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Models\Bot;

function confirmationBot(): Bot
{
    return Bot::factory()->make(['name' => 'Live BTC grid', 'exchange' => 'binance']);
}

it('matches the bot name and exchange phrase', function () {
    $confirmation = new LiveStartConfirmation('Live BTC grid on binance');

    expect($confirmation->matches(confirmationBot()))->toBeTrue();
});

it('rejects a name-only confirmation', function () {
    $confirmation = new LiveStartConfirmation('Live BTC grid');

    expect($confirmation->matches(confirmationBot()))->toBeFalse();
});

it('rejects a confirmation naming a different exchange', function () {
    $confirmation = new LiveStartConfirmation('Live BTC grid on kraken');

    expect($confirmation->matches(confirmationBot()))->toBeFalse();
});

it('trims surrounding whitespace in a submitted confirmation', function () {
    $confirmation = LiveStartConfirmation::fromArray(['confirmation' => '  Live BTC grid on binance  ']);

    expect($confirmation)->not->toBeNull()
        ->and($confirmation->matches(confirmationBot()))->toBeTrue();
});

it('returns null for a missing or blank confirmation', function () {
    expect(LiveStartConfirmation::fromArray([]))->toBeNull()
        ->and(LiveStartConfirmation::fromArray(['confirmation' => '   ']))->toBeNull()
        ->and(LiveStartConfirmation::fromArray(['confirmation' => 42]))->toBeNull();
});
