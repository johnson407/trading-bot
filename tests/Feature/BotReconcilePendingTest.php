<?php

use App\Models\Bot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults reconcile_pending to false and casts it to a boolean', function () {
    $bot = Bot::factory()->create();

    expect($bot->reconcile_pending)->toBeFalse();

    $bot->fill(['reconcile_pending' => true])->save();

    expect($bot->fresh()->reconcile_pending)->toBeTrue();
});

it('provides a reconcile pending factory state', function () {
    $bot = Bot::factory()->reconcilePending()->create();

    expect($bot->fresh()->reconcile_pending)->toBeTrue();
});
