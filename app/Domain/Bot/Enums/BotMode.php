<?php

namespace App\Domain\Bot\Enums;

enum BotMode: string
{
    case Paper = 'paper';
    case Live = 'live';

    public function isLive(): bool
    {
        return $this === self::Live;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
