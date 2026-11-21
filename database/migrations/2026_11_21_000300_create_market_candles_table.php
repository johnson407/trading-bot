<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_candles', function (Blueprint $table) {
            $table->id();
            $table->string('exchange', 64);
            $table->string('symbol', 32);
            $table->string('timeframe', 8);
            $table->timestamp('open_time');
            $table->decimal('open', 32, 16);
            $table->decimal('high', 32, 16);
            $table->decimal('low', 32, 16);
            $table->decimal('close', 32, 16);
            $table->decimal('volume', 32, 16);
            $table->timestamps();

            $table->unique(['exchange', 'symbol', 'timeframe', 'open_time']);
            $table->index(['exchange', 'symbol', 'timeframe', 'open_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_candles');
    }
};
