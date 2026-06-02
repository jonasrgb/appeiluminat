<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_parent_backfill_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->unsignedBigInteger('source_product_id')->nullable();
            $table->string('source_product_gid')->nullable();
            $table->string('source_title')->nullable();
            $table->string('source_handle')->nullable();
            $table->json('source_skus')->nullable();
            $table->string('source_status')->nullable();
            $table->unsignedInteger('source_image_count')->default(0);
            $table->foreignId('target_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedBigInteger('target_product_id');
            $table->string('target_product_gid');
            $table->string('target_title')->nullable();
            $table->string('target_handle')->nullable();
            $table->json('target_skus')->nullable();
            $table->string('target_status')->nullable();
            $table->unsignedInteger('target_image_count')->default(0);
            $table->unsignedBigInteger('parentproduct_value')->nullable();
            $table->string('match_status')->index();
            $table->string('match_strategy')->nullable()->index();
            $table->json('notes')->nullable();
            $table->timestamp('last_scanned_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['target_shop_id', 'target_product_id'], 'pp_backfill_target_unique');
            $table->index(['source_shop_id', 'source_product_id'], 'pp_backfill_source_idx');
            $table->index(['target_shop_id', 'match_status'], 'pp_backfill_target_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_parent_backfill_candidates');
    }
};
