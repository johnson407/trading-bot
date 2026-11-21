<?php

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('surfaces a live confirmation error after a rejected start', function () {
    config(['trading.live_enabled' => true]);

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->create(['status' => 'idle']);

    $this->from(route('bots.show', $bot))
        ->actingAs($user)
        ->post(route('bots.start', $bot), ['confirmation' => 'not the phrase'])
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasErrors('confirmation');

    $this->actingAs($user)
        ->get(route('bots.show', $bot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Show')
            ->where('errors.confirmation', fn ($value) => is_string($value) && $value !== '')
            ->etc());
});

it('surfaces a control action error after a failed pause', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['status' => 'idle']);

    $this->mock(\App\Actions\PauseBot::class, function ($mock) {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('Pause failed.'));
    });

    $this->from(route('bots.show', $bot))
        ->actingAs($user)
        ->post(route('bots.pause', $bot))
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasErrors('action');

    $this->actingAs($user)
        ->get(route('bots.show', $bot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Show')
            ->where('errors.action', 'Pause failed.')
            ->etc());
});

it('rejects a name-only confirmation when starting a live bot', function () {
    config(['trading.live_enabled' => true]);

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->create([
        'name' => 'Live BTC grid',
        'status' => 'idle',
        'capital_allocation' => '1000',
    ]);

    app()->instance(\App\Services\Exchange\ExchangeClientFactory::class, new \App\Services\Exchange\ExchangeClientFactory(live: new \Tests\Fixtures\FakeExchangeClient(quoteBalance: '1000')));

    $this->from(route('bots.show', $bot))
        ->actingAs($user)
        ->post(route('bots.start', $bot), ['confirmation' => 'Live BTC grid'])
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasErrors('confirmation');

    expect($bot->fresh()->status->value)->toBe('idle');

    $this->from(route('bots.show', $bot))
        ->actingAs($user)
        ->post(route('bots.start', $bot), ['confirmation' => 'Live BTC grid on binance'])
        ->assertSessionHasNoErrors();

    expect($bot->fresh()->status->value)->toBe('running');
});

it('shows the exact confirmation phrase in the create and start forms', function () {
    $create = file_get_contents(resource_path('js/Pages/Bots/Create.vue'));
    $show = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($create)->toContain("live_confirmation: ''")
        ->and($create)->toContain('form.errors.live_confirmation')
        ->and($create)->toContain('${form.name} on ${form.exchange}')
        ->and($show)->toContain('bot.name')
        ->and($show)->toContain('bot.exchange')
        ->and($show)->toContain('to confirm live trading');
});

it('renders both control error channels on the bot detail page', function () {
    $page = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($page)->toContain('errors?.confirmation')
        ->and($page)->toContain('errors?.action')
        ->and($page)->toContain('to confirm live trading');
});
