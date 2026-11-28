<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

function alertIngestionMigration(): object
{
    return require database_path('migrations/2026_11_28_000200_add_alert_ingestion_markers.php');
}

function assertAlertIngestionIndexIsValid(string $name): void
{
    $valid = DB::table('pg_index')
        ->whereRaw('indexrelid = ?::regclass', [$name])
        ->value('indisvalid');

    expect($valid)->toBeTrue("Index {$name} should be valid.");
}

it('can run the alert ingestion migration twice without failing', function () {
    $migration = alertIngestionMigration();
    $migration->up();
    $migration->up();

    foreach (['risk_events', 'activity_logs'] as $table) {
        expect(Schema::hasColumns($table, [
            'alert_processed_at',
            'alert_ingest_attempts',
            'alert_next_attempt_at',
        ]))->toBeTrue();
    }

    assertAlertIngestionIndexIsValid('risk_events_unprocessed_idx');
    assertAlertIngestionIndexIsValid('activity_logs_unprocessed_idx');
    assertAlertIngestionIndexIsValid('alerts_source_type_source_id_unique');
});
