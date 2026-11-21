<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('strategy_key', 64);
            $table->json('configuration');
            $table->json('state')->nullable();
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_configs');
    }
};
