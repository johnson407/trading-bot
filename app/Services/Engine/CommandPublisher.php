<?php

namespace App\Services\Engine;

interface CommandPublisher
{
    /**
     * Publish one envelope to the engine command stream.
     *
     * @param  array<string, mixed>  $payload
     * @return string The producer-set envelope id (ULID).
     */
    public function publish(string $type, ?int $userId, ?int $botId, array $payload = []): string;
}
