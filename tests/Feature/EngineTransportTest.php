<?php

use App\Services\Engine\EngineHeartbeat;
use App\Services\Engine\RedisCommandPublisher;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeEngineHeartbeat;

afterEach(fn () => Carbon\Carbon::setTestNow());

it('exposes the engine heartbeat through the health endpoint', function () {
    $heartbeat = app(EngineHeartbeat::class);
    expect($heartbeat)->toBeInstanceOf(FakeEngineHeartbeat::class);

    $heartbeat->available = false;
    $this->get('/health/engine')->assertStatus(503)->assertJson(['available' => false]);

    $heartbeat->available = true;
    $this->get('/health/engine')->assertOk()->assertJson(['available' => true]);
});

it('publishes a v1 envelope to the command stream', function () {
    Carbon\Carbon::setTestNow('2026-11-23T12:00:00+00:00');

    $client = Mockery::mock();
    $client->shouldReceive('xAdd')
        ->once()
        ->with('engine.commands', '*', Mockery::on(function (array $fields): bool {
            $envelope = json_decode($fields['envelope'], true, flags: JSON_THROW_ON_ERROR);

            expect($envelope['v'])->toBe(1)
                ->and($envelope['id'])->toBeString()->toHaveLength(26)
                ->and($envelope['type'])->toBe('bot.start')
                ->and($envelope['ts'])->toBe('2026-11-23T12:00:00+00:00')
                ->and($envelope['user_id'])->toBe(7)
                ->and($envelope['bot_id'])->toBe(9)
                ->and($envelope['payload'])->toBe([]);

            return true;
        }))
        ->andReturn('1700000000000-0');

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    $id = (new RedisCommandPublisher)->publish('bot.start', 7, 9);

    expect($id)->toHaveLength(26);
});

it('routes backtest commands to the backtest stream', function () {
    $client = Mockery::mock();
    $client->shouldReceive('xAdd')
        ->once()
        ->with('engine.backtests', '*', Mockery::on(function (array $fields): bool {
            return json_decode($fields['envelope'], true)['type'] === 'backtest.run';
        }))
        ->andReturn('1700000000000-0');

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    (new RedisCommandPublisher)->publish('backtest.run', 1, 2, ['backtest_run_id' => 3]);
});

it('throws when the stream rejects the command', function (mixed $response) {
    $client = Mockery::mock();
    $client->shouldReceive('xAdd')->once()->andReturn($response);

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    expect(fn () => (new RedisCommandPublisher)->publish('bot.pause', 1, 2))
        ->toThrow(RuntimeException::class, 'The engine command stream rejected bot.pause.');
})->with([
    'false' => false,
    'empty string' => '',
]);

it('reports a fresh engine heartbeat as available', function () {
    Carbon\Carbon::setTestNow('2026-11-23T12:00:00+00:00');

    $client = Mockery::mock();
    $client->shouldReceive('get')
        ->once()
        ->with('engine:heartbeat')
        ->andReturn(json_encode([
            'ts' => '2026-11-23T11:59:58+00:00',
            'revision' => 4,
            'running_bots' => 2,
        ]));

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    $status = (new EngineHeartbeat)->status();

    expect($status['available'])->toBeTrue()
        ->and($status['last_seen_at'])->toBe('2026-11-23T11:59:58+00:00')
        ->and($status['running_bots'])->toBe(2)
        ->and($status['checked_at'])->toBe('2026-11-23T12:00:00+00:00');
});

it('reports missing, malformed, future or expired heartbeats as unavailable', function (mixed $raw) {
    Carbon\Carbon::setTestNow('2026-11-23T12:00:00+00:00');

    $client = Mockery::mock();
    $client->shouldReceive('get')->once()->with('engine:heartbeat')->andReturn($raw);

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    expect((new EngineHeartbeat)->available())->toBeFalse();
})->with([
    'missing' => null,
    'bad json' => 'not-json',
    'expired' => json_encode(['ts' => '2026-11-23T11:59:00+00:00', 'running_bots' => 1]),
    'too far in the future' => json_encode(['ts' => '2026-11-23T12:00:20+00:00', 'running_bots' => 1]),
    'missing ts' => json_encode(['running_bots' => 1]),
]);

it('treats a redis failure as an unavailable heartbeat', function () {
    $client = Mockery::mock();
    $client->shouldReceive('get')->once()->with('engine:heartbeat')->andThrow(new RuntimeException('redis down'));

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    expect((new EngineHeartbeat)->available())->toBeFalse();
});
