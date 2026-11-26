<?php

use App\Actions\PauseBot;
use App\Actions\StartBot;
use App\Actions\StopBot;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Services\Engine\CommandPublisher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not start an archived bot from a stale model', function () {
    $bot = Bot::factory()->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);
    $stale = Bot::query()->findOrFail($bot->id);
    $bot->delete();

    expect(fn () => app(StartBot::class)->handle($stale))
        ->toThrow(ModelNotFoundException::class)
        ->and(Bot::withTrashed()->findOrFail($bot->id)->desired_status)->toBe(BotStatus::Stopped)
        ->and(app(CommandPublisher::class)->ofType('bot.start'))->toHaveCount(0)
        ->and($bot->activityLogs()->where('type', 'bot.started')->count())->toBe(0);
});

it('does not pause an archived bot from a stale model', function () {
    $bot = Bot::factory()->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ]);
    $stale = Bot::query()->findOrFail($bot->id);
    $bot->delete();

    expect(fn () => app(PauseBot::class)->handle($stale))
        ->toThrow(ModelNotFoundException::class)
        ->and(Bot::withTrashed()->findOrFail($bot->id)->desired_status)->toBe(BotStatus::Stopped)
        ->and(app(CommandPublisher::class)->ofType('bot.pause'))->toHaveCount(0)
        ->and($bot->activityLogs()->where('type', 'bot.paused')->count())->toBe(0);
});

it('does not restore a start rollback onto a bot archived during publish', function () {
    $bot = Bot::factory()->create([
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
    ]);

    $publisher = Mockery::mock(CommandPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturnUsing(function () use ($bot): never {
        Bot::query()->whereKey($bot->id)->update([
            'status' => BotStatus::Stopped->value,
            'desired_status' => BotStatus::Stopped->value,
        ]);
        $bot->delete();

        throw new RuntimeException('Command stream unavailable.');
    });
    app()->instance(CommandPublisher::class, $publisher);

    expect(fn () => app(StartBot::class)->handle($bot))
        ->toThrow(RuntimeException::class)
        ->and(Bot::withTrashed()->findOrFail($bot->id)->desired_status)->toBe(BotStatus::Stopped)
        ->and(Bot::withTrashed()->findOrFail($bot->id)->trashed())->toBeTrue()
        ->and($bot->activityLogs()->where('type', 'bot.started')->count())->toBe(0);
});

it('does not restore a pause rollback onto a bot archived during publish', function () {
    $bot = Bot::factory()->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Running,
    ]);

    $publisher = Mockery::mock(CommandPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturnUsing(function () use ($bot): never {
        Bot::query()->whereKey($bot->id)->update([
            'status' => BotStatus::Stopped->value,
            'desired_status' => BotStatus::Stopped->value,
        ]);
        $bot->delete();

        throw new RuntimeException('Command stream unavailable.');
    });
    app()->instance(CommandPublisher::class, $publisher);

    expect(fn () => app(PauseBot::class)->handle($bot))
        ->toThrow(RuntimeException::class)
        ->and(Bot::withTrashed()->findOrFail($bot->id)->desired_status)->toBe(BotStatus::Stopped)
        ->and(Bot::withTrashed()->findOrFail($bot->id)->trashed())->toBeTrue()
        ->and($bot->activityLogs()->where('type', 'bot.paused')->count())->toBe(0);
});

it('does not stop an archived bot from a stale model', function () {
    $bot = Bot::factory()->running()->create();
    $stale = Bot::query()->findOrFail($bot->id);
    $bot->forceFill([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Stopped,
    ])->save();
    $bot->delete();

    expect(fn () => app(StopBot::class)->handle($stale))
        ->toThrow(ModelNotFoundException::class)
        ->and(Bot::withTrashed()->findOrFail($bot->id)->desired_status)->toBe(BotStatus::Stopped)
        ->and(app(CommandPublisher::class)->ofType('bot.stop'))->toHaveCount(0)
        ->and($bot->activityLogs()->where('type', 'bot.stopped')->count())->toBe(0);
});
