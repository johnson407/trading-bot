<?php

namespace App\Domain\Exchange\Enums;

enum ExchangeErrorType: string
{
    case Connection = 'connection';
    case Authentication = 'authentication';
    case RateLimit = 'rate_limit';
    case Rejected = 'rejected';
    case InsufficientBalance = 'insufficient_balance';
    case NotFound = 'not_found';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return in_array($this, [self::Connection, self::RateLimit], true);
    }
}
