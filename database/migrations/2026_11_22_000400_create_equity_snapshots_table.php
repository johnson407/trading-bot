<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->decimal('equity', 20, 8);
            $table->decimal('quote_value', 20, 8);
            $table->decimal('base_value', 20, 8);
            $table->decimal('realized_pnl', 20, 8);
            $table->decimal('unrealized_pnl', 20, 8);
            $table->decimal('fees_paid', 20, 8);
            $table->unsignedInteger('bot_count');
            $table->timestamps();

            $table->unique(['user_id', 'captured_at']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_snapshots');
    }
};
