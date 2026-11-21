<?php

use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Strategy\Exceptions\InvalidStrategyStateException;
use App\Domain\Strategy\StrategyStateLoader;
use App\Domain\Strategy\StrategyStateSerializer;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Models\Bot;
use App\Models\StrategyConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('round-trips versioned strategy state', function () {
    $serializer = app(StrategyStateSerializer::class);
    $state = StrategyState::empty()->merge(['center_price' => '50000', 'fill_count' => 3]);

    $payload = $serializer->serialize($state);

    expect($payload['version'])->toBe(1)
        ->and($serializer->deserialize($payload)->data)->toBe(['center_price' => '50000', 'fill_count' => 3]);
});

it('persists and resumes strategy state from the database', function () {
    $config = StrategyConfig::factory()->create();
    $serializer = app(StrategyStateSerializer::class);
    $state = StrategyState::empty()->merge(['initialized' => true, 'spacing_value' => '100']);

    $serializer->persist($config, $state);

    $resumed = app(StrategyStateLoader::class)->load($config->bot);

    expect($resumed->get('initialized'))->toBeTrue()
        ->and($resumed->get('spacing_value'))->toBe('100')
        ->and($config->fresh()->state_version)->toBe(1);
});

it('rejects missing, unsupported and malformed state versions', function (mixed $payload) {
    expect(fn () => app(StrategyStateSerializer::class)->deserialize($payload, 'simple-grid'))
        ->toThrow(InvalidStrategyStateException::class);
})->with([
    'missing version' => [['data' => []]],
    'unsupported version' => [['version' => 99, 'data' => []]],
    'missing data' => [['version' => 1]],
    'non-array data' => [['version' => 1, 'data' => 'nope']],
]);

it('pauses a bot and records an error when persisted state is unusable', function () {
    $bot = Bot::factory()->running()->create();
    StrategyConfig::factory()->for($bot)->create([
        'state' => ['version' => 99, 'data' => []],
    ]);

    expect(fn () => app(StrategyStateLoader::class)->load($bot->fresh()))
        ->toThrow(InvalidStrategyStateException::class);

    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($bot->fresh()->last_error)->toContain('state version 99')
        ->and($bot->activityLogs()->where('type', 'bot.strategy_state_invalid')->count())->toBe(1);
});

it('pauses a bot with a missing strategy configuration', function () {
    $bot = Bot::factory()->running()->create();

    expect(fn () => app(StrategyStateLoader::class)->load($bot))
        ->toThrow(InvalidStrategyStateException::class);

    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($bot->fresh()->last_error)->toContain('configuration is missing');
});
