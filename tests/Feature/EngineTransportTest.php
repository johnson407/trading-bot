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
    $unavailable = $this->get('/health/engine');
    $unavailable->assertStatus(503)->assertJson(['available' => false]);
    expect($unavailable->headers->get('Cache-Control'))->toContain('no-store');

    $heartbeat->available = true;
    $available = $this->get('/health/engine');
    $available->assertOk()->assertJson(['available' => true]);
    expect($available->headers->get('Cache-Control'))->toContain('no-store');
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

it('parses optional engine status fields', function () {
    Carbon\Carbon::setTestNow('2026-11-23T12:00:00+00:00');

    $client = Mockery::mock();
    $client->shouldReceive('get')
        ->once()
        ->with('engine:heartbeat')
        ->andReturn(json_encode([
            'ts' => '2026-11-23T11:59:58+00:00',
            'revision' => 4,
            'running_bots' => 2,
            'started_at' => '2026-11-23T11:00:00Z',
            'live_trading' => false,
            'market_data' => [
                'configured' => true,
                'mode' => 'websocket',
                'degraded' => false,
                'reason' => '',
                'symbols' => 2,
                'last_message_at' => '2026-11-23T11:59:57Z',
            ],
        ]));

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    $status = (new EngineHeartbeat)->status();

    expect($status['available'])->toBeTrue()
        ->and($status['started_at'])->toBe('2026-11-23T11:00:00+00:00')
        ->and($status['live_trading'])->toBeFalse()
        ->and($status['market_data'])->toBe([
            'configured' => true,
            'mode' => 'websocket',
            'degraded' => false,
            'reason' => '',
            'symbols' => 2,
            'last_message_at' => '2026-11-23T11:59:57+00:00',
        ]);
});

it('reports optional engine status fields as unknown when absent', function () {
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
        ->and($status['started_at'])->toBeNull()
        ->and($status['live_trading'])->toBeNull()
        ->and($status['market_data'])->toBeNull();
});

it('treats malformed optional engine status fields as unknown without affecting availability', function () {
    Carbon\Carbon::setTestNow('2026-11-23T12:00:00+00:00');

    $client = Mockery::mock();
    $client->shouldReceive('get')
        ->once()
        ->with('engine:heartbeat')
        ->andReturn(json_encode([
            'ts' => '2026-11-23T11:59:58+00:00',
            'started_at' => 'not-a-date',
            'live_trading' => 'false',
            'market_data' => 'not-an-object',
        ]));

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    $status = (new EngineHeartbeat)->status();

    expect($status['available'])->toBeTrue()
        ->and($status['started_at'])->toBeNull()
        ->and($status['live_trading'])->toBeNull()
        ->and($status['market_data'])->toBeNull();
});

it('rejects optional timestamps that are not RFC 3339', function (string $timestamp) {
    Carbon\Carbon::setTestNow('2026-11-23T12:00:00+00:00');

    $client = Mockery::mock();
    $client->shouldReceive('get')
        ->once()
        ->with('engine:heartbeat')
        ->andReturn(json_encode([
            'ts' => '2026-11-23T11:59:58+00:00',
            'started_at' => $timestamp,
            'market_data' => [
                'configured' => true,
                'mode' => 'websocket',
                'degraded' => false,
                'last_message_at' => $timestamp,
            ],
        ]));

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    $status = (new EngineHeartbeat)->status();

    expect($status['available'])->toBeTrue()
        ->and($status['started_at'])->toBeNull()
        ->and($status['market_data']['last_message_at'])->toBeNull();
})->with([
    'relative' => 'yesterday',
    'date only' => '2026-11-23',
    'missing offset' => '2026-11-23T11:00:00',
]);

it('parses partial market data without inventing false values', function () {
    Carbon\Carbon::setTestNow('2026-11-23T12:00:00+00:00');

    $client = Mockery::mock();
    $client->shouldReceive('get')
        ->once()
        ->with('engine:heartbeat')
        ->andReturn(json_encode([
            'ts' => '2026-11-23T11:59:58+00:00',
            'market_data' => [
                'mode' => 'rest',
                'configured' => 'true',
                'degraded' => 0,
                'symbols' => '2',
                'last_message_at' => 'not-a-date',
            ],
        ]));

    Redis::shouldReceive('connection')->once()->with('engine')->andReturn($client);

    $status = (new EngineHeartbeat)->status();

    expect($status['available'])->toBeTrue()
        ->and($status['market_data'])->toBe([
            'configured' => null,
            'mode' => 'rest',
            'degraded' => null,
            'reason' => null,
            'symbols' => null,
            'last_message_at' => null,
        ]);
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
