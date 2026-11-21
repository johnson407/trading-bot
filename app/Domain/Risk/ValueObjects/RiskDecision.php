<?php

namespace App\Domain\Risk\ValueObjects;

use App\Domain\Risk\Enums\RiskSeverity;

readonly class RiskDecision
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public bool $allowed,
        public ?string $type = null,
        public ?string $message = null,
        public array $context = [],
        public RiskSeverity $severity = RiskSeverity::Warning,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function block(
        string $type,
        string $message,
        array $context = [],
        RiskSeverity $severity = RiskSeverity::Warning,
    ): self {
        return new self(false, $type, $message, $context, $severity);
    }
}
