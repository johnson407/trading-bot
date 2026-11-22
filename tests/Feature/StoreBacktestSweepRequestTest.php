<?php

use App\Http\Requests\StoreBacktestSweepRequest;
use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function sweepValidationBot(User $user, array $bot = [], array $config = []): Bot
{
    $bot = Bot::factory()->for($user)->create($bot);
    StrategyConfig::factory()->for($bot)->create($config);

    return $bot;
}

function sweepPayload(Bot $bot, array $overrides = []): array
{
    return array_merge([
        'bot_id' => $bot->id,
        'timeframe' => '15m',
        'start_at' => now('UTC')->subDays(2)->format('Y-m-d'),
        'end_at' => now('UTC')->subDay()->format('Y-m-d'),
        'name' => 'Momentum sweep',
        'center_prices' => ['50000', '51000'],
        'spacing_values' => ['100'],
        'level_counts' => [4, 5],
    ], $overrides);
}

/** @return array<string, array<int, string>> */
function sweepValidationErrors(User $user, array $payload): array
{
    $request = StoreBacktestSweepRequest::create('/backtest-sweeps', 'POST', $payload);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->setUserResolver(fn () => $user);

    try {
        $request->validateResolved();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('accepts a valid multi-value grid sweep', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    expect(sweepValidationErrors($user, sweepPayload($bot)))->toBe([]);
});

it('accepts exactly two combinations', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    expect(sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => ['50000', '51000'],
        'spacing_values' => ['100'],
        'level_counts' => [4],
    ])))->toBe([]);
});

it('accepts exactly the configured maximum number of combinations', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    expect(sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => ['50000', '51000', '52000', '53000'],
        'spacing_values' => ['100', '200', '300'],
        'level_counts' => [4, 5],
    ])))->toBe([]);
});

it('rejects an oversized sweep', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    $errors = sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => ['50000', '51000', '52000', '53000', '54000'],
        'spacing_values' => ['100', '200', '300'],
        'level_counts' => [4, 5],
    ]));

    expect($errors)->toHaveKey('parameters');
});

it('rejects duplicate values within a dimension', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    foreach ([
        'center_prices' => ['50000', '50000.0'],
        'spacing_values' => ['100', '100.00'],
        'level_counts' => [4, 4],
    ] as $field => $values) {
        $errors = sweepValidationErrors($user, sweepPayload($bot, [$field => $values]));

        expect($errors)->toHaveKey($field);
    }
});

it('rejects duplicate numeric values even when submitted as floats', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    $errors = sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => [50000, 50000.0],
    ]));

    expect($errors)->toHaveKey('center_prices');
});

it('rejects exponent notation instead of throwing while checking duplicates', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    $errors = sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => ['1e3', '51000'],
    ]));

    expect($errors)->toHaveKey('center_prices.0');
});

it('rejects a set that produces only one combination', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    $errors = sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => ['50000'],
        'spacing_values' => ['100'],
        'level_counts' => [4],
    ]));

    expect($errors)->toHaveKey('parameters');
});

it('accepts each eligible grid strategy and rejects dynamic grids', function (string $strategy) {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user, ['strategy_key' => $strategy]);

    expect(sweepValidationErrors($user, sweepPayload($bot)))->toBe([]);
})->with(['simple-grid', 'trailing-grid', 'martingale-grid']);

it('rejects dynamic and unknown strategies', function () {
    $user = User::factory()->create();

    foreach (['dynamic-grid', 'fake'] as $strategy) {
        $bot = sweepValidationBot($user, ['strategy_key' => $strategy]);

        expect(sweepValidationErrors($user, sweepPayload($bot)))->toHaveKey('bot_id');
    }
});

it('rejects boolean, nested, and excessive precision values explicitly', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    $errors = sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => [true, '51000'],
        'spacing_values' => [['100'], '200'],
        'level_counts' => [true, 5],
    ]));

    expect($errors)->toHaveKeys(['center_prices.0', 'spacing_values.0', 'level_counts.0']);

    $precision = sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => ['1.00000000000000001', '51000'],
        'spacing_values' => ['1.00000000000000001'],
    ]));

    expect($precision)->toHaveKeys(['center_prices.0', 'spacing_values.0']);
});

it('rejects a non-grid strategy and another owner bot', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $nonGrid = sweepValidationBot($user, ['strategy_key' => 'fake']);
    $foreign = sweepValidationBot($other);

    expect(sweepValidationErrors($user, sweepPayload($nonGrid)))->toHaveKey('bot_id')
        ->and(sweepValidationErrors($user, sweepPayload($foreign)))->toHaveKey('bot_id');
});

it('rejects malformed bot and timeframe payloads without server errors', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    expect(sweepValidationErrors($user, sweepPayload($bot, ['bot_id' => 'abc'])))->toHaveKey('bot_id')
        ->and(sweepValidationErrors($user, sweepPayload($bot, ['timeframe' => ['15m']])))->toHaveKey('timeframe');
});

it('rejects invalid dates and positive decimal or level violations', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);
    $zero = sweepValidationErrors($user, sweepPayload($bot, [
        'level_counts' => [0, 51],
    ]));

    expect(sweepValidationErrors($user, sweepPayload($bot, [
        'start_at' => now('UTC')->format('Y-m-d'),
        'end_at' => now('UTC')->subDay()->format('Y-m-d'),
    ])))->toHaveKey('end_at')
        ->and(sweepValidationErrors($user, sweepPayload($bot, [
            'end_at' => now('UTC')->addDay()->format('Y-m-d'),
        ])))->toHaveKey('end_at')
        ->and(sweepValidationErrors($user, sweepPayload($bot, [
            'center_prices' => ['0', '-1'],
        ])))->toHaveKey('center_prices.0')
        ->and(sweepValidationErrors($user, sweepPayload($bot, [
            'spacing_values' => ['nope'],
        ])))->toHaveKey('spacing_values.0')
        ->and($zero)->toHaveKey('level_counts.0')
        ->and(sweepValidationErrors($user, sweepPayload($bot, [
            'level_counts' => [4.5],
        ])))->toHaveKey('level_counts.0');
});

it('rejects empty value lists', function () {
    $user = User::factory()->create();
    $bot = sweepValidationBot($user);

    $errors = sweepValidationErrors($user, sweepPayload($bot, [
        'center_prices' => [],
        'spacing_values' => [],
        'level_counts' => [],
    ]));

    expect($errors)->toHaveKeys(['center_prices', 'spacing_values', 'level_counts']);
});
