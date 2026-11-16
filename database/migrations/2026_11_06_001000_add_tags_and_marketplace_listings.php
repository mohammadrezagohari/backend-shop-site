<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'posts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('tags')->nullable());
        }
        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 50);
            $table->boolean('enabled')->default(true);
            $table->string('external_id')->nullable();
            $table->json('settings')->nullable();
            $table->string('status')->default('not_configured');
            $table->text('last_error')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
        foreach (['products', 'posts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('tags'));
        }
    }
};
