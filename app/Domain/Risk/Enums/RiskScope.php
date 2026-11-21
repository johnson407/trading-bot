<?php

namespace App\Domain\Risk\Enums;

enum RiskScope: string
{
    case User = 'user';
    case Bot = 'bot';
}
