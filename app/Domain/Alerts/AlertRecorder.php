<?php

namespace App\Domain\Alerts;

use App\Jobs\SendAlertEmail;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\RiskEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AlertRecorder
{
    public function __construct(private readonly AlertSanitizer $sanitizer) {}

    public function fromRiskEvent(RiskEvent $event): ?Alert
    {
        $outcome = $this->recordRiskEvent($event);

        return $outcome->status === AlertRecordStatus::Created ? $outcome->alert : null;
    }

    public function fromActivity(ActivityLog $activity): ?Alert
    {
        $outcome = $this->recordActivity($activity);

        return $outcome->status === AlertRecordStatus::Created ? $outcome->alert : null;
    }

    public function recordRiskEvent(RiskEvent $event): AlertRecordOutcome
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

    public function recordActivity(ActivityLog $activity): AlertRecordOutcome
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
    public function recordSystemIncident(
        string $type,
        string $message,
        int $userId,
        ?int $botId = null,
        array $context = [],
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): AlertRecordOutcome {
        return $this->record($type, $message, $botId, $userId, $context, $sourceType, $sourceId);
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
        ?string $sourceType,
        ?int $sourceId,
    ): AlertRecordOutcome {
        if (! config('alerts.enabled', true)) {
            return new AlertRecordOutcome(AlertRecordStatus::Disabled);
        }

        $incidents = config('alerts.incidents', []);

        if (! is_array($incidents) || ! isset($incidents[$type]) || ! is_array($incidents[$type])) {
            return new AlertRecordOutcome(AlertRecordStatus::NotConfigured);
        }

        $incident = $incidents[$type];

        if (! isset($incident['severity'], $incident['title'])) {
            return new AlertRecordOutcome(AlertRecordStatus::NotConfigured);
        }

        if ($sourceType !== null && $sourceId !== null) {
            $existing = $this->existingSourceAlert($sourceType, $sourceId);

            if ($existing !== null) {
                return new AlertRecordOutcome(AlertRecordStatus::Deduplicated, $existing);
            }
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
            if ($this->isDuplicateSourceViolation($exception) && $sourceType !== null && $sourceId !== null) {
                $existing = $this->existingSourceAlert($sourceType, $sourceId);

                if ($existing !== null) {
                    return new AlertRecordOutcome(AlertRecordStatus::Deduplicated, $existing);
                }
            }

            $this->logFailure($type, $userId, $botId, $exception);

            return new AlertRecordOutcome(AlertRecordStatus::Failed);
        }

        if (! $alert->wasRecentlyCreated) {
            return new AlertRecordOutcome(AlertRecordStatus::Deduplicated, $alert);
        }

        if ($alert->severity === 'critical') {
            $this->dispatchEmail($alert);
        }

        return new AlertRecordOutcome(AlertRecordStatus::Created, $alert);
    }

    private function existingSourceAlert(string $sourceType, int $sourceId): ?Alert
    {
        try {
            return Alert::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->orderBy('id')
                ->first();
        } catch (Throwable $exception) {
            $this->logFailure($sourceType, 0, null, $exception);

            return null;
        }
    }

    private function isDuplicateSourceViolation(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && (string) $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'alerts_source_type_source_id_unique');
    }

    private function logFailure(string $type, int $userId, ?int $botId, Throwable $exception): void
    {
        Log::error('Failed to record alert.', [
            'type' => $type,
            'user_id' => $userId,
            'bot_id' => $botId,
            'error' => $this->sanitizer->text($exception->getMessage()),
        ]);
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
