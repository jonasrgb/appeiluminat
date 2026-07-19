<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unique(['id', 'shop_id'], 'catalog_audit_runs_id_shop_unique');
            $table->string('status', 20)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('missing_image_count')->default(0);
            $table->unsignedInteger('duplicate_sku_group_count')->default(0);
            $table->unsignedInteger('duplicate_sku_row_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_audit_runs');
    }
};
