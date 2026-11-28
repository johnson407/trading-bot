<?php

namespace App\Domain\Alerts;

use App\Models\Alert;

final readonly class AlertRecordOutcome
{
    public function __construct(
        public AlertRecordStatus $status,
        public ?Alert $alert = null,
    ) {}

    public function shouldMarkProcessed(): bool
    {
        return $this->status->shouldMarkProcessed();
    }
}
