<?php

namespace App\Domain\Alerts;

enum AlertRecordStatus: string
{
    case Created = 'created';
    case Deduplicated = 'deduplicated';
    case NotConfigured = 'not_configured';
    case Disabled = 'disabled';
    case Failed = 'failed';

    public function shouldMarkProcessed(): bool
    {
        return $this !== self::Failed;
    }
}
