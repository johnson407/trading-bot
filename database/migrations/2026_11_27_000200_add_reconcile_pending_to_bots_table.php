<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Set only by a running paper edit that changes strategy structure
            // or capital allocation. The engine cancels open orders and resets
            // strategy state before placing the new ladder.
            $table->boolean('reconcile_pending')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('reconcile_pending');
        });
    }
};
