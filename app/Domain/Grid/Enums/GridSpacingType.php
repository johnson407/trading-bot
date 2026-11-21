<?php

namespace App\Domain\Grid\Enums;

enum GridSpacingType: string
{
    case Arithmetic = 'arithmetic';
    case Geometric = 'geometric';
    case Dynamic = 'dynamic';
}
