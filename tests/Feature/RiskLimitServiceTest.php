<?php

use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\RiskEventRecorder;
use App\Domain\Risk\RiskLimitService;
use App\Models\Bot;
use App\Models\RiskLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back to the user limits until a bot override is created', function () {
    $bot = Bot::factory()->create();
    $service = app(RiskLimitService::class);

    $userLimit = $service->forUser($bot->user);

    expect($service->forUser($bot->user)->is($userLimit))->toBeTrue()
        ->and($service->forBot($bot)->is($userLimit))->toBeTrue()
        ->and($service->forBot($bot)->scope->value)->toBe('user')
        ->and($userLimit->max_position_notional)->toBe('1000.0000000000000000')
        ->and($service->limitsFor($bot))->not->toHaveKey('bot');

    $override = $service->updateForBot($bot, ['max_open_orders' => 3]);

    expect($override->scope->value)->toBe('bot')
        ->and($service->forBot($bot)->is($override))->toBeTrue()
        ->and($service->limitsFor($bot)['bot']->is($override))->toBeTrue();
});

it('resolves effective user limits without writing a row', function () {
    $user = User::factory()->create();
    $service = app(RiskLimitService::class);

    $limits = $service->effectiveForUser($user);

    expect($limits['max_position_notional']->toString())->toBe('1000')
        ->and($limits['max_open_orders'])->toBe(20)
        ->and($limits['daily_loss_limit']->toString())->toBe('100')
        ->and(RiskLimit::count())->toBe(0);

    $service->updateForUser($user, ['max_open_orders' => 7]);

    expect($service->effectiveForUser($user)['max_open_orders'])->toBe(7);
});

it('updates global and per-bot risk limits', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $service = app(RiskLimitService::class);

    $global = $service->updateForUser($user, [
        'max_position_notional' => '5000',
        'max_open_orders' => 50,
        'daily_loss_limit' => '250',
    ]);
    $botLimit = $service->updateForBot($bot, [
        'max_position_notional' => '750',
        'max_open_orders' => 10,
    ]);

    expect($global->max_position_notional)->toBe('5000.0000000000000000')
        ->and($global->max_open_orders)->toBe(50)
        ->and($botLimit->max_position_notional)->toBe('750.0000000000000000')
        ->and($botLimit->max_open_orders)->toBe(10)
        ->and($botLimit->daily_loss_limit)->toBe('100.0000000000000000');
});

it('rejects invalid risk limit updates', function () {
    $bot = Bot::factory()->create();
    $service = app(RiskLimitService::class);

    expect(fn () => $service->updateForBot($bot, ['max_position_notional' => '-1']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->updateForBot($bot, ['max_open_orders' => -1]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->updateForBot($bot, ['unknown' => '1']))
        ->toThrow(InvalidArgumentException::class);
});

it('exposes every risk breach by bot and user', function () {
    $bot = Bot::factory()->create();
    $other = Bot::factory()->for($bot->user)->create();
    $recorder = app(RiskEventRecorder::class);

    $recorder->record('risk.position_limit', 'Position limit', $bot, severity: RiskSeverity::Critical);
    $recorder->record('risk.open_order_limit', 'Open order limit', $bot, severity: RiskSeverity::Warning);
    $recorder->record('risk.daily_loss_limit', 'Daily loss', $other, severity: RiskSeverity::Critical);

    $service = app(RiskLimitService::class);

    expect($service->eventsForBot($bot))->toHaveCount(2)
        ->and($service->eventsForUser($bot->user))->toHaveCount(3)
        ->and($service->eventsForBot($bot)->first()->type)->toBe('risk.open_order_limit');
});
