<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exchange_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('exchange', 64)->default('binance');
            $table->string('symbol', 32);
            $table->string('strategy_key', 64);
            $table->string('mode', 16)->default('paper');
            $table->string('status', 16)->default('idle');
            $table->decimal('capital_allocation', 32, 16)->default(0);
            $table->decimal('base_inventory', 32, 16)->default(0);
            $table->decimal('quote_inventory', 32, 16)->default(0);
            $table->decimal('average_entry_price', 32, 16)->default(0);
            $table->decimal('realized_pnl', 32, 16)->default(0);
            $table->decimal('fees_paid', 32, 16)->default(0);
            $table->decimal('fee_rate', 16, 8)->default(0);
            $table->unsignedInteger('tick_interval_seconds')->default(1);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_tick_at')->nullable();
            $table->timestamp('last_successful_tick_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['user_id', 'status']);
            $table->index('exchange');
            $table->index('symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
