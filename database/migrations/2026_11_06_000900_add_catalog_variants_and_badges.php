<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete());
        Schema::table('products', fn (Blueprint $table) => $table->boolean('has_variants')->default(false));
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100)->unique();
            $table->json('attributes');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('old_price')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('cart_items', function (Blueprint $table): void {
            // Keep a non-null line key so simple products also have database-enforced uniqueness.
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->unique(['cart_id', 'product_id', 'variant_key'], 'cart_product_variant_unique');
            $table->dropUnique(['cart_id', 'product_id']);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('variant_sku', 100)->nullable();
            $table->json('selected_attributes')->nullable();
        });
        Schema::create('site_badges', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('image_url', 2048);
            $table->string('link_url', 2048)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_badges');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn(['variant_sku', 'selected_attributes']);
        });
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_product_variant_unique');
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn('variant_key');
            // Existing multiple variant lines must be resolved before rolling back in a populated database.
            $table->unique(['cart_id', 'product_id']);
        });
        Schema::dropIfExists('product_variants');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('has_variants'));
        Schema::table('categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_id'));
    }
};
