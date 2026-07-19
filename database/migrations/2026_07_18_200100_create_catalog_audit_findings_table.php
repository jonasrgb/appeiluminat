<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('last_seen_run_id');
            $table->string('finding_type', 30)->index();
            $table->string('fingerprint');
            $table->string('product_gid');
            $table->unsignedBigInteger('product_legacy_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('product_handle')->nullable();
            $table->string('product_status')->nullable();
            $table->string('variant_gid')->nullable();
            $table->unsignedBigInteger('variant_legacy_id')->nullable();
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable();
            $table->string('normalized_sku')->nullable();
            $table->string('shopify_admin_url')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['shop_id', 'finding_type', 'fingerprint'],
                'catalog_audit_finding_identity_unique'
            );
            $table->index(['shop_id', 'finding_type', 'normalized_sku']);
            $table->foreign(
                ['last_seen_run_id', 'shop_id'],
                'catalog_audit_findings_run_shop_foreign'
            )->references(['id', 'shop_id'])->on('catalog_audit_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_audit_findings');
    }
};
