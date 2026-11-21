<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('exchange', 64);
            $table->string('symbol', 32);
            $table->string('strategy_key', 64);
            $table->string('timeframe', 8);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->decimal('capital_allocation', 32, 16);
            $table->decimal('fee_rate', 16, 8);
            $table->json('config_snapshot');
            $table->string('status', 24)->default('pending');
            $table->string('end_reason', 24)->nullable();
            $table->unsignedInteger('candles_processed')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('metrics')->nullable();
            $table->json('equity_curve')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['symbol', 'timeframe', 'start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_runs');
    }
};
