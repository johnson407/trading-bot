<?php

namespace App\Domain\Bot\Enums;

enum BotStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case Error = 'error';

    public function isRunnable(): bool
    {
        return $this === self::Running;
    }

    public function isTerminal(): bool
    {
        return $this === self::Stopped;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
