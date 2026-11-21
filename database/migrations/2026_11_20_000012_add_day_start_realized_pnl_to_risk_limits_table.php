<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_limits', function (Blueprint $table) {
            $table->decimal('day_start_realized_pnl', 32, 16)->default(0)->after('day_start');
        });
    }

    public function down(): void
    {
        Schema::table('risk_limits', function (Blueprint $table) {
            $table->dropColumn('day_start_realized_pnl');
        });
    }
};
