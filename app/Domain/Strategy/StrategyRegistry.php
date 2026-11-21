<?php

namespace App\Domain\Strategy;

use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Exceptions\UnknownStrategyException;

class StrategyRegistry
{
    /** @var array<string, StrategyInterface> */
    private array $strategies = [];

    public function register(StrategyInterface $strategy): void
    {
        $this->strategies[$strategy->key()] = $strategy;
    }

    public function has(string $key): bool
    {
        return isset($this->strategies[$key]);
    }

    public function get(string $key): StrategyInterface
    {
        if (! isset($this->strategies[$key])) {
            throw UnknownStrategyException::forKey($key, array_keys($this->strategies));
        }

        return $this->strategies[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->strategies);
    }

    /** @return array<string, StrategyInterface> */
    public function all(): array
    {
        return $this->strategies;
    }
}
