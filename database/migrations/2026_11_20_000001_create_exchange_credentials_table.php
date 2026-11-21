<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('exchange', 64);
            $table->text('api_key');
            $table->text('api_secret');
            $table->text('passphrase')->nullable();
            $table->boolean('testnet')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'exchange']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_credentials');
    }
};
