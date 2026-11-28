<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The grid activity view reads canceled orders by bot and close time. Without
 * this index that window query scans every order the bot has ever placed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['bot_id', 'closed_at'], 'orders_bot_id_closed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_bot_id_closed_at_index');
        });
    }
};
