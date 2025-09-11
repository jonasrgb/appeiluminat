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
        Schema::table('product_mirrors', function (Blueprint $table) {
            //
            // Fingerprint-uri rapide pentru diff
            $table->string('product_fingerprint', 64)->nullable()->after('target_product_gid');
            $table->string('options_fingerprint', 64)->nullable()->after('product_fingerprint');

            // Snapshot minim normalizat
            $table->json('last_snapshot')->nullable()->after('options_fingerprint');

            // Indecși utili pentru căutări/diff
            $table->index(['source_shop_id', 'source_product_id'], 'pm_src_shop_prod_idx');
            $table->index(['target_shop_id', 'target_product_id'], 'pm_tgt_shop_prod_idx');
            $table->index('target_product_gid', 'pm_tgt_gid_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_mirrors', function (Blueprint $table) {
            $table->dropIndex('pm_src_shop_prod_idx');
            $table->dropIndex('pm_tgt_shop_prod_idx');
            $table->dropIndex('pm_tgt_gid_idx');

            $table->dropColumn(['product_fingerprint', 'options_fingerprint', 'last_snapshot']);
        });
    }
};
