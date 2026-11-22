<?php

namespace App\Domain\Grid\ValueObjects;

use App\Domain\Grid\Enums\GridPlanProblemSeverity;

final readonly class GridPlanProblem
{
    public function __construct(
        public string $code,
        public ?string $field,
        public string $message,
        public GridPlanProblemSeverity $severity = GridPlanProblemSeverity::Error,
    ) {}

    public function isError(): bool
    {
        return $this->severity === GridPlanProblemSeverity::Error;
    }

    /** @return array{code: string, field: string|null, message: string, severity: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'field' => $this->field,
            'message' => $this->message,
            'severity' => $this->severity->value,
        ];
    }
}
