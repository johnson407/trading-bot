<?php

namespace App\Domain\Risk\Enums;

enum RiskSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
