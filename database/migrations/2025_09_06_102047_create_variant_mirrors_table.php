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
        Schema::create('variant_mirrors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_mirror_id')
                ->constrained('product_mirrors')
                ->cascadeOnDelete();

            // Mapare variantă sursă → țintă
            $table->unsignedBigInteger('source_variant_id')->nullable()->index();

            // Cheie canonică a opțiunilor (ex: "color=red|size=m")
            $table->string('source_options_key', 191)->nullable()->index();

            $table->unsignedBigInteger('target_variant_id')->nullable()->index();
            $table->string('target_variant_gid', 191)->nullable()->index();

            // Fingerprint-uri pt. diffs rapide
            $table->string('variant_fingerprint', 64)->nullable();
            $table->string('inventory_fingerprint', 64)->nullable();

            // Snapshot minim normalizat (include cel puțin inventory_item_gid)
            $table->json('last_snapshot')->nullable();

            $table->timestamps();

            // O variantă sursă mapată o singură dată în acel mirror de produs
            $table->unique(['product_mirror_id', 'source_variant_id'], 'vm_prod_srcvar_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variant_mirrors');
    }
};
