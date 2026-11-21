<?php

namespace App\Domain\Grid\Enums;

enum GridStatus: string
{
    case Active = 'active';
    case Stopped = 'stopped';
    case Completed = 'completed';
}
