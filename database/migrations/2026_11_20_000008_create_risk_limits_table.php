<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_limits', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('max_position_notional', 32, 16)->nullable();
            $table->unsignedInteger('max_open_orders')->nullable();
            $table->decimal('daily_loss_limit', 32, 16)->nullable();
            $table->timestamp('day_start')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'scope']);
            $table->index('bot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_limits');
    }
};
