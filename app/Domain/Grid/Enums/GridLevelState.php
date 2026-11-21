<?php

namespace App\Domain\Grid\Enums;

enum GridLevelState: string
{
    case Idle = 'idle';
    case Open = 'open';
    case Filled = 'filled';
    case Canceled = 'canceled';
}
