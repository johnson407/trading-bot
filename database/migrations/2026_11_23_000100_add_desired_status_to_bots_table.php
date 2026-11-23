<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split user intent (`desired_status`, Laravel-owned) from runtime state
 * (`status`, engine-owned after cutover).
 *
 * Backfill makes the migration safe for existing rows. After the later Laravel
 * wiring phase, Laravel writes only `desired_status`; the Go engine applies it
 * and writes `status` and `last_error`. This migration does not change any
 * start/pause/stop action yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('desired_status', 16)->default('idle')->after('status');
            $table->index('desired_status');
        });

        DB::table('bots')->update(['desired_status' => DB::raw('status')]);
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('desired_status');
        });
    }
};
