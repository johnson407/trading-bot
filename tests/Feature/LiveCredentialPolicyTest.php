<?php

use App\Actions\CreateBot;
use App\Actions\StartBot;
use App\Domain\Risk\Exceptions\LiveCredentialConflictException;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function liveCredentialPayload(string $name = 'Second live grid'): array
{
    return [
        'name' => $name,
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'mode' => 'live',
        'capital_allocation' => '1000',
        'fee_rate' => '0',
        'center_price' => '50000',
        'level_count' => 6,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'order_quantity' => '0.001',
        'configuration' => [
            'center_price' => '50000',
            'level_count' => 6,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ],
    ];
}

function liveCredentialUser(): array
{
    $user = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($user)->create(['exchange' => 'binance']);

    return [$user, $credential];
}

function startLiveBot(Bot $bot, string $balance = '10000'): mixed
{
    config()->set('trading.live_enabled', true);

    return app(StartBot::class)->handle(
        $bot,
        new LiveStartConfirmation(LiveStartConfirmation::phrase($bot)),
        new FakeExchangeClient(quoteBalance: $balance),
    );
}

it('rejects creating a live bot when the credential already has an active live bot', function () {
    [$user, $credential] = liveCredentialUser();
    Bot::factory()->live()->running()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'name' => 'First live grid',
    ]);

    expect(fn () => app(CreateBot::class)->handle($user, liveCredentialPayload()))
        ->toThrow(LiveCredentialConflictException::class);
});

it('rejects a second start while the first start is still in flight', function () {
    [$user, $credential] = liveCredentialUser();
    Bot::factory()->live()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'name' => 'First live grid',
        'status' => 'idle',
        'desired_status' => 'running',
    ]);
    $second = Bot::factory()->live()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'name' => 'Second live grid',
    ]);

    expect(fn () => startLiveBot($second))
        ->toThrow(LiveCredentialConflictException::class);
});

it('allows resuming the same paused live bot', function () {
    [$user, $credential] = liveCredentialUser();
    $bot = Bot::factory()->live()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'status' => 'paused',
        'desired_status' => 'paused',
    ]);

    $started = startLiveBot($bot);

    expect($started->desired_status->value)->toBe('running');
});

it('allows a new live bot after the previous one stops', function () {
    [$user, $credential] = liveCredentialUser();
    Bot::factory()->live()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'status' => 'stopped',
        'desired_status' => 'stopped',
    ]);
    $next = Bot::factory()->live()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'name' => 'Next live grid',
    ]);

    expect(startLiveBot($next)->desired_status->value)->toBe('running');
});

it('does not let paper bots block a live bot', function () {
    [$user, $credential] = liveCredentialUser();
    Bot::factory()->running()->count(2)->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'mode' => 'paper',
    ]);
    $live = Bot::factory()->live()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
    ]);

    expect(startLiveBot($live)->desired_status->value)->toBe('running');
});

it('rejects a live bot without a credential', function () {
    [$user] = liveCredentialUser();
    $bot = Bot::factory()->live()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => null,
    ]);

    expect(fn () => startLiveBot($bot))
        ->toThrow(LiveCredentialConflictException::class);
});

it('surfaces a create conflict as a mode validation error', function () {
    [$user, $credential] = liveCredentialUser();
    $this->mock(CreateBot::class, function ($mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->andThrow(LiveCredentialConflictException::missingCredential());
    });

    $this->actingAs($user)
        ->from(route('bots.create'))
        ->post(route('bots.store'), [
            ...liveCredentialPayload(),
            'live_confirmation' => 'Second live grid on binance',
        ])
        ->assertRedirect(route('bots.create'))
        ->assertSessionHasErrors('mode');

    expect(Bot::query()->where('name', 'Second live grid')->exists())->toBeFalse();
    expect($credential->id)->toBeInt();
});

it('surfaces a start conflict as an operator action error', function () {
    [$user, $credential] = liveCredentialUser();
    Bot::factory()->live()->running()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'name' => 'First live grid',
    ]);
    $second = Bot::factory()->live()->create([
        'user_id' => $user->id,
        'exchange_credential_id' => $credential->id,
        'name' => 'Second live grid',
    ]);
    config()->set('trading.live_enabled', true);

    $this->actingAs($user)
        ->from(route('bots.show', $second))
        ->post(route('bots.start', $second), [
            'confirmation' => LiveStartConfirmation::phrase($second),
        ])
        ->assertRedirect(route('bots.show', $second))
        ->assertSessionHasErrors('action');

    expect($second->refresh()->desired_status->value)->toBe('idle');
});
