<?php

namespace App\Domain\Grid\Enums;

enum GridPlanProblemSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
