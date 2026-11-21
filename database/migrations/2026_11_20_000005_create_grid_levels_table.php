<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grid_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('price', 32, 16);
            $table->string('side', 8);
            $table->string('state', 16)->default('idle');
            $table->timestamps();

            $table->unique(['grid_id', 'sequence']);
            $table->index(['grid_id', 'price']);
            $table->index(['grid_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_levels');
    }
};
