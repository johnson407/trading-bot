<?php

use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('redirects guests away from authenticated pages', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/profile')->assertRedirect('/login');
});

it('allows only the owner to view and control a bot', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create();

    expect(Gate::forUser($owner)->allows('view', $bot))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $bot))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('control', $bot))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $bot))->toBeTrue()
        ->and(Gate::forUser($other)->allows('view', $bot))->toBeFalse()
        ->and(Gate::forUser($other)->allows('control', $bot))->toBeFalse()
        ->and(Gate::allows('view', $bot))->toBeFalse();
});

it('allows only the owner to manage exchange credentials', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($owner)->create();

    expect(Gate::forUser($owner)->allows('view', $credential))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $credential))->toBeTrue()
        ->and(Gate::forUser($other)->allows('update', $credential))->toBeFalse()
        ->and(Gate::forUser($other)->allows('delete', $credential))->toBeFalse();
});
