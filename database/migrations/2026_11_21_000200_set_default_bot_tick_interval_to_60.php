<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bots ALTER COLUMN tick_interval_seconds SET DEFAULT 60');

        DB::table('bots')
            ->where('tick_interval_seconds', 1)
            ->update(['tick_interval_seconds' => 60]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bots ALTER COLUMN tick_interval_seconds SET DEFAULT 1');
    }
};
