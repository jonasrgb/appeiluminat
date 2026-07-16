<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_product_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedBigInteger('source_product_id');
            $table->unsignedBigInteger('webhook_event_id')->nullable();
            $table->timestamp('deleted_at');
            $table->timestamps();

            $table->unique(['source_shop_id', 'source_product_id'], 'source_product_deletions_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_product_deletions');
    }
};
