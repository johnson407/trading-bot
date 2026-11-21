<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->decimal('center_price', 32, 16);
            $table->string('spacing_type', 24);
            $table->unsignedInteger('level_count');
            $table->decimal('spacing_value', 32, 16)->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamps();

            $table->index(['bot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grids');
    }
};
