<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

        Schema::table('risk_events', function (Blueprint $table) {
            $table->timestamp('alert_processed_at')->nullable();
            $table->unsignedSmallInteger('alert_ingest_attempts')->default(0);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->timestamp('alert_processed_at')->nullable();
            $table->unsignedSmallInteger('alert_ingest_attempts')->default(0);
        });

        DB::table('risk_events')->update(['alert_processed_at' => now()]);
        DB::table('activity_logs')->update(['alert_processed_at' => now()]);

        DB::statement('CREATE INDEX risk_events_unprocessed_idx ON risk_events (id) WHERE alert_processed_at IS NULL');
        DB::statement('CREATE INDEX activity_logs_unprocessed_idx ON activity_logs (id) WHERE alert_processed_at IS NULL');

        Schema::table('alerts', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id'], 'alerts_source_type_source_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropUnique('alerts_source_type_source_id_unique');
        });

        DB::statement('DROP INDEX IF EXISTS risk_events_unprocessed_idx');
        DB::statement('DROP INDEX IF EXISTS activity_logs_unprocessed_idx');

        Schema::table('risk_events', function (Blueprint $table) {
            $table->dropColumn(['alert_processed_at', 'alert_ingest_attempts']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['alert_processed_at', 'alert_ingest_attempts']);
        });
    }
};
