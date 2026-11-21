<?php

namespace App\Domain\Strategy;

use App\Domain\Strategy\Exceptions\InvalidStrategyStateException;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Models\StrategyConfig;

class StrategyStateSerializer
{
    public const VERSION = 1;

    /** @return array{version: int, data: array<string, mixed>} */
    public function serialize(StrategyState $state): array
    {
        if ($state->version > self::VERSION) {
            throw InvalidStrategyStateException::forStrategy('*', "state version {$state->version} is newer than supported version ".self::VERSION);
        }

        return [
            'version' => self::VERSION,
            'data' => $state->data,
        ];
    }

    /** @param array<string, mixed>|null $payload */
    public function deserialize(?array $payload, string $strategyKey = '*'): StrategyState
    {
        if ($payload === null) {
            return StrategyState::empty(self::VERSION);
        }

        if (! array_key_exists('version', $payload) || ! is_int($payload['version'])) {
            throw InvalidStrategyStateException::forStrategy($strategyKey, 'the state version is missing or invalid');
        }

        if ($payload['version'] !== self::VERSION) {
            throw InvalidStrategyStateException::forStrategy(
                $strategyKey,
                "state version {$payload['version']} is not supported (expected ".self::VERSION.')',
            );
        }

        if (! array_key_exists('data', $payload) || ! is_array($payload['data'])) {
            throw InvalidStrategyStateException::forStrategy($strategyKey, 'the state data is missing or invalid');
        }

        return new StrategyState(self::VERSION, $payload['data']);
    }

    public function persist(StrategyConfig $config, StrategyState $state): void
    {
        $config->forceFill([
            'state' => $this->serialize($state),
            'state_version' => self::VERSION,
        ])->save();
    }
}
