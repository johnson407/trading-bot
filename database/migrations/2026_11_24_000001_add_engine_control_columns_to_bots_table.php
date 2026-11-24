<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->timestamp('kill_switch_requested_at')->nullable()->after('desired_status');
            $table->boolean('strategy_reset_pending')->default(false)->after('kill_switch_requested_at');
            $table->boolean('cancel_retry_requested')->default(false)->after('strategy_reset_pending');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['kill_switch_requested_at', 'strategy_reset_pending', 'cancel_retry_requested']);
        });
    }
};
