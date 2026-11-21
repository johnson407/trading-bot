<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grid_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grid_level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_order_id', 120);
            $table->string('exchange_order_id')->nullable();
            $table->string('mode', 16);
            $table->string('side', 8);
            $table->string('type', 16)->default('limit');
            $table->decimal('price', 32, 16);
            $table->decimal('quantity', 32, 16);
            $table->decimal('filled_quantity', 32, 16)->default(0);
            $table->string('status', 24)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['bot_id', 'client_order_id']);
            $table->index('status');
            $table->index(['bot_id', 'status']);
            $table->index('exchange_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
