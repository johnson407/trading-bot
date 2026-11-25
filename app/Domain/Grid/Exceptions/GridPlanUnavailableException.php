<?php

namespace App\Domain\Grid\Exceptions;

use RuntimeException;

class GridPlanUnavailableException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("The plan evaluator is unavailable: {$reason}");
    }
}
