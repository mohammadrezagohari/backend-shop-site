<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('payment_reference')->nullable()->after('payment_status')->index();
            $table->string('payment_token_hash', 64)->nullable()->after('payment_reference');
            $table->timestamp('paid_at')->nullable()->after('payment_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['payment_reference']);
            $table->dropColumn(['payment_reference', 'payment_token_hash', 'paid_at']);
        });
    }
};
