<?php

namespace App\Domain\Activity;

use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\User;
use InvalidArgumentException;

class ActivityRecorder
{
    /**
     * Record a user- or bot-attributed activity event.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $type,
        string $message,
        ?Bot $bot = null,
        ?User $user = null,
        array $context = [],
        string $severity = 'info',
    ): ActivityLog {
        $user ??= $bot?->user;

        if (! $user instanceof User) {
            throw new InvalidArgumentException('An activity event requires a user or a bot with a user.');
        }

        return ActivityLog::create([
            'user_id' => $user->id,
            'bot_id' => $bot?->id,
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
