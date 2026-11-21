<?php

namespace App\Domain\Exchange;

use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use Throwable;

class ExchangeErrorMapper
{
    public function map(Throwable $exception, string $exchange, string $operation): ExchangeException
    {
        if ($exception instanceof ExchangeException) {
            return $exception;
        }

        return ExchangeException::make(
            $this->typeFor($exception),
            $exchange,
            $operation,
            $exception->getMessage(),
            ['exception' => $exception::class],
            $exception,
        );
    }

    public function typeFor(Throwable $exception): ExchangeErrorType
    {
        return match (true) {
            $this->isA($exception, 'ccxt\\AuthenticationError') => ExchangeErrorType::Authentication,
            $this->isA($exception, 'ccxt\\InsufficientFunds') => ExchangeErrorType::InsufficientBalance,
            $this->isA($exception, 'ccxt\\OrderNotFound') => ExchangeErrorType::NotFound,
            $this->isA($exception, 'ccxt\\InvalidOrder') => ExchangeErrorType::Rejected,
            $this->isA($exception, 'ccxt\\RateLimitExceeded') => ExchangeErrorType::RateLimit,
            $this->isA($exception, 'ccxt\\DDoSProtection') => ExchangeErrorType::RateLimit,
            $this->isA($exception, 'ccxt\\NetworkError') => ExchangeErrorType::Connection,
            $this->isA($exception, 'ccxt\\RequestTimeout') => ExchangeErrorType::Connection,
            $this->isA($exception, 'ccxt\\ExchangeNotAvailable') => ExchangeErrorType::Connection,
            $this->isA($exception, 'ccxt\\NotSupported') => ExchangeErrorType::Unsupported,
            $this->isA($exception, 'ccxt\\ExchangeError') => ExchangeErrorType::Rejected,
            default => ExchangeErrorType::Unknown,
        };
    }

    private function isA(Throwable $exception, string $class): bool
    {
        return class_exists($class) && $exception instanceof $class;
    }
}
