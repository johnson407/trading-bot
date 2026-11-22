<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->decimal('stop_loss_percent', 8, 4)->nullable();
            $table->decimal('take_profit_percent', 8, 4)->nullable();
            $table->decimal('range_low', 16, 8)->nullable();
            $table->decimal('range_high', 16, 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn([
                'stop_loss_percent',
                'take_profit_percent',
                'range_low',
                'range_high',
            ]);
        });
    }
};
