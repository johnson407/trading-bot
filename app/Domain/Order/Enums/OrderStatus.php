<?php

namespace App\Domain\Order\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Open = 'open';
    case PartiallyFilled = 'partially_filled';
    case Filled = 'filled';
    case Canceled = 'canceled';
    case Rejected = 'rejected';

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Open, self::PartiallyFilled], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Filled, self::Canceled, self::Rejected], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Pending => in_array($next, [self::Open, self::Canceled, self::Rejected], true),
            self::Open => in_array($next, [self::PartiallyFilled, self::Filled, self::Canceled, self::Rejected], true),
            self::PartiallyFilled => in_array($next, [self::Filled, self::Canceled], true),
            self::Filled, self::Canceled, self::Rejected => false,
        };
    }
}
