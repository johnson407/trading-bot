<?php

namespace App\Console\Commands;

use App\Domain\Alerts\AlertRecorder;
use App\Models\ActivityLog;
use App\Models\RiskEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IngestEngineAlerts extends Command
{
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

        foreach ([RiskEvent::class, ActivityLog::class] as $model) {
            $ids = $model::query()
                ->whereNull('alert_processed_at')
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            foreach ($ids as $id) {
                if ($this->ingest($model, (int) $id, $recorder)) {
                    $ingested++;
                }
            }
        }

        $this->info("Processed {$ingested} engine event(s).");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function ingest(string $model, int $id, AlertRecorder $recorder): bool
    {
        try {
            return DB::transaction(function () use ($model, $id, $recorder): bool {
                /** @var RiskEvent|ActivityLog|null $row */
                $row = $model::query()
                    ->whereKey($id)
                    ->whereNull('alert_processed_at')
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    return false;
                }

                $outcome = $row instanceof RiskEvent
                    ? $recorder->recordRiskEvent($row)
                    : $recorder->recordActivity($row);

                if (! $outcome->shouldMarkProcessed()) {
                    throw new AlertIngestionFailed;
                }

                $model::query()->whereKey($id)->update([
                    'alert_processed_at' => now(),
                    'alert_ingest_attempts' => 0,
                ]);

                return true;
            });
        } catch (AlertIngestionFailed) {
            $this->incrementAttempts($model, $id);

            return false;
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function incrementAttempts(string $model, int $id): void
    {
        DB::transaction(function () use ($model, $id): void {
            $table = (new $model)->getTable();

            $updated = DB::table($table)
                ->where('id', $id)
                ->whereNull('alert_processed_at')
                ->increment('alert_ingest_attempts');

            if ($updated === 0) {
                return;
            }

            $row = DB::table($table)->where('id', $id)->first();

            if ($row === null || (int) $row->alert_ingest_attempts < 3) {
                return;
            }

            DB::table($table)->where('id', $id)->update([
                'alert_processed_at' => now(),
                'alert_ingest_attempts' => 3,
            ]);

            Log::warning('Marked an engine event processed after repeated alert ingestion failures.', [
                'table' => $table,
                'id' => $id,
            ]);
        });
    }
}

class AlertIngestionFailed extends RuntimeException {}
