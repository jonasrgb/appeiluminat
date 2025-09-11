<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shop_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('target_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unique(['source_shop_id','target_shop_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_connections');
    }
};
