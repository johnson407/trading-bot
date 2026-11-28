<?php

namespace App\Console\Commands;

use App\Domain\Alerts\AlertIngestionFailed;
use App\Domain\Alerts\AlertRecorder;
use App\Models\ActivityLog;
use App\Models\RiskEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestEngineAlerts extends Command
{
    private const MAX_ATTEMPTS_WITHOUT_SIGNAL = 6;

    protected $signature = 'alerts:ingest-engine-events';

    protected $description = 'Create alerts for engine-written risk events and activity logs';

    public function handle(AlertRecorder $recorder): int
    {
        if (! config('alerts.engine_ingest_enabled', true)) {
            $this->info('Engine event alert ingestion is disabled.');

            return self::SUCCESS;
        }

        $batch = max(1, (int) config('alerts.engine_ingest_batch', 100));
        $ingested = 0;
        $failed = 0;

        foreach ([RiskEvent::class, ActivityLog::class] as $model) {
            $ids = $model::query()
                ->whereNull('alert_processed_at')
                ->where(function ($query): void {
                    $query->whereNull('alert_next_attempt_at')
                        ->orWhere('alert_next_attempt_at', '<=', now());
                })
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            foreach ($ids as $id) {
                $result = $this->ingest($model, (int) $id, $recorder);

                if ($result === 'processed') {
                    $ingested++;
                } elseif ($result === 'failed') {
                    $failed++;
                }
            }
        }

        $this->info("Processed {$ingested} engine event(s).");

        if ($failed > 0) {
            $this->error("{$failed} engine event(s) could not be alerted and will be retried.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function ingest(string $model, int $id, AlertRecorder $recorder): string
    {
        try {
            return DB::transaction(function () use ($model, $id, $recorder): string {
                /** @var RiskEvent|ActivityLog|null $row */
                $row = $model::query()
                    ->whereKey($id)
                    ->whereNull('alert_processed_at')
                    ->lock('for update skip locked')
                    ->first();

                if ($row === null) {
                    return 'skipped';
                }

                $outcome = $row instanceof RiskEvent
                    ? $recorder->recordRiskEvent($row)
                    : $recorder->recordActivity($row);

                if (! $outcome->shouldMarkProcessed()) {
                    throw new AlertIngestionFailed($outcome->status->value);
                }

                $model::query()->whereKey($id)->update([
                    'alert_processed_at' => now(),
                    'alert_ingest_attempts' => 0,
                    'alert_next_attempt_at' => null,
                ]);

                return 'processed';
            });
        } catch (AlertIngestionFailed $exception) {
            $this->recordFailure($model, $id, $exception->getMessage(), $recorder);

            return 'failed';
        } catch (Throwable $exception) {
            $this->recordFailure(
                $model,
                $id,
                $exception::class.': '.$exception->getMessage(),
                $recorder,
            );

            return 'failed';
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function recordFailure(string $model, int $id, string $error, AlertRecorder $recorder): void
    {
        try {
            $failure = DB::transaction(function () use ($model, $id): ?array {
                $table = (new $model)->getTable();
                $row = DB::table($table)
                    ->where('id', $id)
                    ->whereNull('alert_processed_at')
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    return null;
                }

                $attempts = (int) $row->alert_ingest_attempts + 1;
                $delaySeconds = min(300, 10 * (2 ** min($attempts - 1, 5)));

                DB::table($table)->where('id', $id)->update([
                    'alert_ingest_attempts' => $attempts,
                    'alert_next_attempt_at' => now()->addSeconds($delaySeconds),
                ]);

                return ['row' => $row, 'attempts' => $attempts];
            });
        } catch (Throwable $exception) {
            Log::error('Could not record an alert ingestion failure.', [
                'table' => (new $model)->getTable(),
                'id' => $id,
                'error_type' => $exception::class,
            ]);

            return;
        }

        if ($failure === null || $failure['attempts'] < self::MAX_ATTEMPTS_WITHOUT_SIGNAL) {
            return;
        }

        $row = $failure['row'];
        $table = (new $model)->getTable();

        Log::error('Engine event alert ingestion keeps failing.', [
            'table' => $table,
            'id' => $id,
            'attempts' => $failure['attempts'],
            'error' => $error,
        ]);

        $outcome = $recorder->recordSystemIncident(
            type: 'alerts.ingestion_failed',
            message: 'Safety alert ingestion failed repeatedly and will keep retrying.',
            userId: (int) $row->user_id,
            botId: $row->bot_id === null ? null : (int) $row->bot_id,
            context: [
                'table' => $table,
                'event_id' => $id,
                'event_type' => $row->type,
                'attempts' => $failure['attempts'],
                'error' => $error,
            ],
            sourceType: 'alerts.ingestion_failed:'.$table,
            sourceId: $id,
        );

        if ($outcome->status->value === 'failed') {
            Log::error('Could not create the alert ingestion failure alert.', [
                'table' => $table,
                'id' => $id,
            ]);
        }
    }
}
