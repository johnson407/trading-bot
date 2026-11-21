<?php

namespace App\Domain\Risk\Exceptions;

use App\Domain\Support\Decimal;
use RuntimeException;

class InsufficientLiveBalanceException extends RuntimeException
{
    public function __construct(
        public readonly string $asset,
        public readonly Decimal $required,
        public readonly Decimal $available,
        int $botId,
    ) {
        parent::__construct(sprintf(
            'Insufficient %s balance for live bot %d: required %s, available %s.',
            $asset,
            $botId,
            $required->toString(),
            $available->toString(),
        ));
    }
}
