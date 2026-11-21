<?php

namespace App\Domain\Exchange\Exceptions;

use App\Domain\Exchange\Enums\ExchangeErrorType;
use RuntimeException;
use Throwable;

class ExchangeException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly ExchangeErrorType $type,
        public readonly string $exchange,
        public readonly string $operation,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(
        ExchangeErrorType $type,
        string $exchange,
        string $operation,
        string $message,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self($type, $exchange, $operation, $message, $context, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->type->isRetryable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'exchange' => $this->exchange,
            'operation' => $this->operation,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
