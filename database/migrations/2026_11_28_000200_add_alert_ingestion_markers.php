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

        DB::statement("SET lock_timeout = '5s'");

        // Adding a column with a stable default is metadata-only on PostgreSQL
        // 11+, then dropping the default leaves new rows unprocessed.
        DB::statement(
            'ALTER TABLE risk_events '
            .'ADD COLUMN alert_processed_at timestamp(0) without time zone DEFAULT now(), '
            .'ADD COLUMN alert_ingest_attempts smallint NOT NULL DEFAULT 0, '
            .'ADD COLUMN alert_next_attempt_at timestamp(0) without time zone NULL'
        );
        DB::statement('ALTER TABLE risk_events ALTER COLUMN alert_processed_at DROP DEFAULT');

        DB::statement(
            'ALTER TABLE activity_logs '
            .'ADD COLUMN alert_processed_at timestamp(0) without time zone DEFAULT now(), '
            .'ADD COLUMN alert_ingest_attempts smallint NOT NULL DEFAULT 0, '
            .'ADD COLUMN alert_next_attempt_at timestamp(0) without time zone NULL'
        );
        DB::statement('ALTER TABLE activity_logs ALTER COLUMN alert_processed_at DROP DEFAULT');

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS risk_events_unprocessed_idx ON risk_events (id) WHERE alert_processed_at IS NULL');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS activity_logs_unprocessed_idx ON activity_logs (id) WHERE alert_processed_at IS NULL');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS alerts_source_type_source_id_unique ON alerts (source_type, source_id)');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS alerts_source_type_source_id_index');

        DB::statement('SET lock_timeout = 0');
    }

    public function down(): void
    {
        DB::statement("SET lock_timeout = '5s'");

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS alerts_source_type_source_id_unique');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS alerts_source_type_source_id_index ON alerts (source_type, source_id)');

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS risk_events_unprocessed_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS activity_logs_unprocessed_idx');

        DB::statement(
            'ALTER TABLE risk_events '
            .'DROP COLUMN alert_processed_at, '
            .'DROP COLUMN alert_ingest_attempts, '
            .'DROP COLUMN alert_next_attempt_at'
        );
        DB::statement(
            'ALTER TABLE activity_logs '
            .'DROP COLUMN alert_processed_at, '
            .'DROP COLUMN alert_ingest_attempts, '
            .'DROP COLUMN alert_next_attempt_at'
        );

        DB::statement('SET lock_timeout = 0');
    }
};
