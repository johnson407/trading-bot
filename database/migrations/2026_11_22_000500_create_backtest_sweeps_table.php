<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_sweeps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('symbol', 32);
            $table->string('timeframe', 8);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->json('parameters');
            $table->unsignedInteger('total_runs');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->foreignId('sweep_id')
                ->nullable()
                ->index()
                ->after('bot_id')
                ->constrained('backtest_sweeps')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sweep_id');
        });

        Schema::dropIfExists('backtest_sweeps');
    }
};
