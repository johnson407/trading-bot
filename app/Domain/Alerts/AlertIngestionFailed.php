<?php

namespace App\Domain\Alerts;

use RuntimeException;

class AlertIngestionFailed extends RuntimeException
{
    public function __construct(public readonly string $alertStatus)
    {
        parent::__construct("Alert recording returned {$alertStatus}.");
    }
}
