<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->text('pause_reason')->nullable()->after('last_error');
            $table->timestamp('pause_reason_recorded_at')->nullable()->after('pause_reason');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bots
            ADD CONSTRAINT bots_pause_reason_check
            CHECK (
                pause_reason IS NULL
                OR pause_reason IN (
                    'stale_tick',
                    'risk_limit',
                    'protective_exit',
                    'daily_loss',
                    'kill_switch',
                    'reconciliation_conflict',
                    'tick_failure',
                    'live_gate',
                    'strategy_stop',
                    'unsupported_exchange',
                    'user'
                )
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bots DROP CONSTRAINT IF EXISTS bots_pause_reason_check');

        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['pause_reason', 'pause_reason_recorded_at']);
        });
    }
};
