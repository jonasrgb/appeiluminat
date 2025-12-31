<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->string('shop_domain');
            $table->unsignedBigInteger('product_id');
            $table->string('product_gid');
            $table->string('status')->default('pending');
            $table->unsignedInteger('images_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_domain', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media_processes');
    }
};
