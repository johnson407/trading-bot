<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Concurrent index creation cannot run inside a transaction.
     *
     * @var bool
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->assertNoDuplicateSources();

        DB::statement("SET lock_timeout = '5s'");

        try {
            // Adding a column with a stable default is metadata-only on PostgreSQL
            // 11+, then dropping the default leaves new rows unprocessed. IF NOT
            // EXISTS makes an entrypoint retry safe when a prior attempt committed
            // one ALTER before the lock timeout.
            DB::transaction(function (): void {
                DB::statement(
                    'ALTER TABLE risk_events '
                    .'ADD COLUMN IF NOT EXISTS alert_processed_at timestamp(0) without time zone DEFAULT now(), '
                    .'ADD COLUMN IF NOT EXISTS alert_ingest_attempts smallint NOT NULL DEFAULT 0, '
                    .'ADD COLUMN IF NOT EXISTS alert_next_attempt_at timestamp(0) without time zone NULL'
                );
                DB::statement('ALTER TABLE risk_events ALTER COLUMN alert_processed_at DROP DEFAULT');

                DB::statement(
                    'ALTER TABLE activity_logs '
                    .'ADD COLUMN IF NOT EXISTS alert_processed_at timestamp(0) without time zone DEFAULT now(), '
                    .'ADD COLUMN IF NOT EXISTS alert_ingest_attempts smallint NOT NULL DEFAULT 0, '
                    .'ADD COLUMN IF NOT EXISTS alert_next_attempt_at timestamp(0) without time zone NULL'
                );
                DB::statement('ALTER TABLE activity_logs ALTER COLUMN alert_processed_at DROP DEFAULT');
            });

            $this->ensureIndex(
                'risk_events_unprocessed_idx',
                'CREATE INDEX CONCURRENTLY risk_events_unprocessed_idx ON risk_events (id) WHERE alert_processed_at IS NULL'
            );
            $this->ensureIndex(
                'activity_logs_unprocessed_idx',
                'CREATE INDEX CONCURRENTLY activity_logs_unprocessed_idx ON activity_logs (id) WHERE alert_processed_at IS NULL'
            );
            $this->ensureIndex(
                'alerts_source_type_source_id_unique',
                'CREATE UNIQUE INDEX CONCURRENTLY alerts_source_type_source_id_unique ON alerts (source_type, source_id)'
            );

            if ($this->indexExists('alerts_source_type_source_id_index')) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS alerts_source_type_source_id_index');
            }
        } finally {
            DB::statement('SET lock_timeout = 0');
        }
    }

    public function down(): void
    {
        DB::statement("SET lock_timeout = '5s'");

        try {
            if ($this->indexExists('alerts_source_type_source_id_unique')) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS alerts_source_type_source_id_unique');
            }
            $this->ensureIndex(
                'alerts_source_type_source_id_index',
                'CREATE INDEX CONCURRENTLY alerts_source_type_source_id_index ON alerts (source_type, source_id)'
            );

            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS risk_events_unprocessed_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS activity_logs_unprocessed_idx');

            DB::transaction(function (): void {
                DB::statement(
                    'ALTER TABLE risk_events '
                    .'DROP COLUMN IF EXISTS alert_processed_at, '
                    .'DROP COLUMN IF EXISTS alert_ingest_attempts, '
                    .'DROP COLUMN IF EXISTS alert_next_attempt_at'
                );
                DB::statement(
                    'ALTER TABLE activity_logs '
                    .'DROP COLUMN IF EXISTS alert_processed_at, '
                    .'DROP COLUMN IF EXISTS alert_ingest_attempts, '
                    .'DROP COLUMN IF EXISTS alert_next_attempt_at'
                );
            });
        } finally {
            DB::statement('SET lock_timeout = 0');
        }
    }

    private function assertNoDuplicateSources(): void
    {
        $duplicates = DB::table('alerts')
            ->selectRaw('source_type, source_id, count(*) as aggregate')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->groupBy('source_type', 'source_id')
            ->havingRaw('count(*) > 1')
            ->orderBy('source_type')
            ->orderBy('source_id')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add the alert source uniqueness constraint: duplicate alert sources exist. '
                .'Run `php artisan alerts:check-source-duplicates` and resolve them first.'
            );
        }
    }

    private function ensureIndex(string $name, string $createSql): void
    {
        if ($this->indexIsValid($name)) {
            return;
        }

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        DB::statement($createSql);

        if (! $this->indexIsValid($name)) {
            throw new RuntimeException("Index {$name} is invalid after creation; retry the migration to rebuild it.");
        }
    }

    private function indexExists(string $name): bool
    {
        $index = DB::selectOne('SELECT to_regclass(?) AS index', [$name]);

        return $index !== null && $index->index !== null;
    }

    private function indexIsValid(string $name): bool
    {
        $index = DB::selectOne(
            'SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)',
            [$name]
        );

        return $index !== null && (bool) $index->indisvalid;
    }
};
