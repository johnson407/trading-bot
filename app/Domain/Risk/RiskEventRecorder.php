<?php

namespace App\Domain\Risk;

use App\Domain\Alerts\AlertRecorder;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Models\Bot;
use App\Models\RiskEvent;
use App\Models\User;
use InvalidArgumentException;

class RiskEventRecorder
{
    public function __construct(private readonly AlertRecorder $alerts) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $type,
        string $message,
        ?Bot $bot = null,
        ?User $user = null,
        array $context = [],
        RiskSeverity $severity = RiskSeverity::Warning,
    ): RiskEvent {
        $user ??= $bot?->user;

        if (! $user instanceof User) {
            throw new InvalidArgumentException('A risk event requires a user or a bot with a user.');
        }

        $event = RiskEvent::create([
            'user_id' => $user->id,
            'bot_id' => $bot?->id,
            'type' => $type,
            'severity' => $severity->value,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);

        $this->alerts->fromRiskEvent($event);

        return $event;
    }
}
