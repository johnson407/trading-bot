<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fill_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('side', 8);
            $table->decimal('quantity', 32, 16);
            $table->decimal('price', 32, 16);
            $table->decimal('base_delta', 32, 16);
            $table->decimal('quote_delta', 32, 16);
            $table->decimal('base_balance', 32, 16);
            $table->decimal('quote_balance', 32, 16);
            $table->decimal('average_entry_price', 32, 16);
            $table->decimal('realized_pnl', 32, 16)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledger_entries');
    }
};
