<?php

namespace App\Console\Commands;

use App\Domain\Alerts\AlertRecorder;
use App\Models\Bot;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Console\Command;

class CheckEngineHeartbeat extends Command
{
    protected $signature = 'alerts:check-engine-heartbeat';

    protected $description = 'Create an alert when the engine heartbeat is missing for live accounts';

    public function handle(EngineHeartbeat $heartbeat, AlertRecorder $recorder): int
    {
        if (! config('alerts.engine_ingest_enabled', true)) {
            return self::SUCCESS;
        }

        if ($heartbeat->available()) {
            return self::SUCCESS;
        }

        $status = $heartbeat->status();
        $userIds = Bot::query()
            ->where('mode', 'live')
            ->whereIn('status', ['running', 'paused'])
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $recorder->recordSystemIncident(
                type: 'engine.heartbeat_missing',
                message: 'The trading engine heartbeat is missing or expired.',
                userId: (int) $userId,
                context: [
                    'last_seen_at' => $status['last_seen_at'] ?? null,
                    'checked_at' => $status['checked_at'] ?? null,
                ],
            );
        }

        if ($userIds->isNotEmpty()) {
            $this->warn("Engine heartbeat is unavailable; checked {$userIds->count()} live account(s).");
        }

        return self::SUCCESS;
    }
}
