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
        Schema::create('product_mirrors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedBigInteger('source_product_id')->nullable();
            $table->string('source_product_gid')->nullable();
            $table->foreignId('target_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedBigInteger('target_product_id')->nullable();
            $table->string('target_product_gid')->nullable();
            $table->timestamps();
            $table->unique(['source_shop_id','source_product_id','target_shop_id'], 'src_prod_target_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_mirrors');
    }
};
