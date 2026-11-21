<?php

namespace App\Domain\Strategy\ValueObjects;

final readonly class CancelEvent
{
    public function __construct(
        public string $clientOrderId,
        public ?int $gridLevelSequence = null,
        public string $reason = 'canceled',
    ) {
    }
}
