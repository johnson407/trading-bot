<?php

namespace App\Domain\Order\ValueObjects;

use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Models\Fill;
use App\Models\Order;

readonly class FillResult
{
    public function __construct(
        public Fill $fill,
        public Order $order,
        public bool $duplicate = false,
        public ?OrderIntent $opposite = null,
    ) {
    }
}
