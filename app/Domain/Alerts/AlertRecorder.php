<?php

namespace App\Domain\Alerts;

use App\Jobs\SendAlertEmail;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\RiskEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AlertRecorder
{
    public function __construct(private readonly AlertSanitizer $sanitizer) {}

    public function fromRiskEvent(RiskEvent $event): ?Alert
    {
        return $this->record(
            type: $event->type,
            message: $event->message,
            botId: $event->bot_id,
            userId: $event->user_id,
            context: $event->context ?? [],
            sourceType: $event->getMorphClass(),
            sourceId: $event->id,
        );
    }

    public function fromActivity(ActivityLog $activity): ?Alert
    {
        return $this->record(
            type: $activity->type,
            message: $activity->message,
            botId: $activity->bot_id,
            userId: $activity->user_id,
            context: $activity->context ?? [],
            sourceType: $activity->getMorphClass(),
            sourceId: $activity->id,
        );
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function record(
        string $type,
        string $message,
        ?int $botId,
        int $userId,
        array $context,
        string $sourceType,
        int $sourceId,
    ): ?Alert {
        if (! config('alerts.enabled', true)) {
            return null;
        }

        $incidents = config('alerts.incidents', []);

        if (! is_array($incidents) || ! isset($incidents[$type]) || ! is_array($incidents[$type])) {
            return null;
        }

        $incident = $incidents[$type];

        if (! isset($incident['severity'], $incident['title'])) {
            return null;
        }

        $now = now();
        $window = max(1, (int) config('alerts.dedupe_window_seconds', 900));
        $dedupeKey = hash('sha256', "user:{$userId}|bot:".($botId ?? 'account')."|type:{$type}");
        $dedupeBucket = intdiv($now->getTimestamp(), $window);

        try {
            $alert = DB::transaction(function () use ($context, $userId, $botId, $type, $incident, $message, $sourceType, $sourceId, $now, $dedupeKey, $dedupeBucket): Alert {
                $sanitizedContext = $this->sanitizer->context($context);

                return Alert::query()->createOrFirst(
                    [
                        'dedupe_key' => $dedupeKey,
                        'dedupe_bucket' => $dedupeBucket,
                    ],
                    [
                        'user_id' => $userId,
                        'bot_id' => $botId,
                        'type' => $type,
                        'severity' => (string) $incident['severity'],
                        'title' => (string) $incident['title'],
                        'message' => $this->sanitizer->text($message),
                        'context' => $sanitizedContext === [] ? null : $sanitizedContext,
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'created_at' => $now,
                    ],
                );
            });
        } catch (Throwable $exception) {
            Log::error('Failed to record alert.', [
                'type' => $type,
                'user_id' => $userId,
                'bot_id' => $botId,
                'error' => $this->sanitizer->text($exception->getMessage()),
            ]);

            return null;
        }

        if (! $alert->wasRecentlyCreated) {
            return null;
        }

        if ($alert->severity === 'critical') {
            $this->dispatchEmail($alert);
        }

        return $alert;
    }

    private function dispatchEmail(Alert $alert): void
    {
        try {
            DB::afterCommit(function () use ($alert): void {
                try {
                    SendAlertEmail::dispatch($alert->id);
                } catch (Throwable $exception) {
                    Log::error('Failed to dispatch alert email.', [
                        'alert_id' => $alert->id,
                        'error' => $this->sanitizer->text($exception->getMessage()),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Log::error('Failed to schedule alert email.', [
                'alert_id' => $alert->id,
                'error' => $this->sanitizer->text($exception->getMessage()),
            ]);
        }
    }
}
