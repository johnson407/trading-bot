<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('exchange', 64);
            $table->string('exchange_fill_id');
            $table->string('side', 8);
            $table->decimal('price', 32, 16);
            $table->decimal('quantity', 32, 16);
            $table->decimal('fee', 32, 16)->default(0);
            $table->string('fee_currency', 16)->nullable();
            $table->timestamp('filled_at');
            $table->timestamps();

            $table->unique(['exchange', 'exchange_fill_id']);
            $table->index(['bot_id', 'filled_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fills');
    }
};
