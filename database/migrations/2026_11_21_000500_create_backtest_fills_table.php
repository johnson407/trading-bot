<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_fills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('side', 8);
            $table->decimal('price', 32, 16);
            $table->decimal('quantity', 32, 16);
            $table->decimal('fee', 32, 16);
            $table->string('fee_currency', 16);
            $table->timestamp('filled_at');
            $table->unsignedInteger('grid_level_sequence')->nullable();
            $table->timestamps();

            $table->unique(['backtest_run_id', 'sequence']);
            $table->index(['backtest_run_id', 'filled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_fills');
    }
};
