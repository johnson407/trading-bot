<?php

namespace Tests\Support;

use App\Services\Engine\CommandPublisher;
use RuntimeException;

class FakeCommandPublisher implements CommandPublisher
{
    /** @var list<array{type:string,user_id:?int,bot_id:?int,payload:array<string,mixed>,id:string}> */
    public array $commands = [];

    public bool $fail = false;

    public function publish(string $type, ?int $userId, ?int $botId, array $payload = []): string
    {
        if ($this->fail) {
            throw new RuntimeException('Command stream unavailable.');
        }

        $id = 'cmd-'.(count($this->commands) + 1);
        $this->commands[] = [
            'type' => $type,
            'user_id' => $userId,
            'bot_id' => $botId,
            'payload' => $payload,
            'id' => $id,
        ];

        return $id;
    }

    /** @return list<array{type:string,user_id:?int,bot_id:?int,payload:array<string,mixed>,id:string}> */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->commands, fn (array $command) => $command['type'] === $type));
    }
}
