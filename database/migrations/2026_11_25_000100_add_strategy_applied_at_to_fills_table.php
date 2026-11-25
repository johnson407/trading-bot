<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live fills are inserted during exchange reconciliation, which can run before
 * or between ticks. The strategy still has to see each fill through OnFill.
 * This marker makes that hand-off crash-safe: a fill is only marked applied in
 * the same transaction that persists the strategy state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fills', function (Blueprint $table) {
            $table->timestamp('strategy_applied_at')->nullable()->after('fee_currency');
            $table->index(['bot_id', 'strategy_applied_at']);
        });
    }

    public function down(): void
    {
        Schema::table('fills', function (Blueprint $table) {
            $table->dropIndex(['bot_id', 'strategy_applied_at']);
            $table->dropColumn('strategy_applied_at');
        });
    }
};
