<?php

namespace App\Domain\Order\Enums;

enum OrderType: string
{
    case Limit = 'limit';
    case Market = 'market';
}
