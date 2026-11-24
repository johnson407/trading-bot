<?php

namespace App\Services\Engine;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

class RedisCommandPublisher implements CommandPublisher
{
    public function __construct(
        private readonly string $connection = 'engine',
        private readonly string $stream = 'engine.commands',
        private readonly string $backtestStream = 'engine.backtests',
    ) {}

    public function publish(string $type, ?int $userId, ?int $botId, array $payload = []): string
    {
        $id = (string) Str::ulid();

        $envelope = [
            'v' => 1,
            'id' => $id,
            'type' => $type,
            'ts' => now('UTC')->toIso8601String(),
            'user_id' => $userId,
            'bot_id' => $botId,
            'payload' => $payload === [] ? new stdClass : $payload,
        ];

        $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $stream = str_starts_with($type, 'backtest.') ? $this->backtestStream : $this->stream;

        $entry = Redis::connection($this->connection)->xAdd(
            $stream,
            '*',
            ['envelope' => $encoded],
        );

        if ($entry === false || $entry === '') {
            throw new RuntimeException("The engine command stream rejected {$type}.");
        }

        return $id;
    }
}
