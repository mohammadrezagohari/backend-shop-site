<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('specifications')->nullable()->after('description');
            $table->longText('additional_description')->nullable()->after('specifications');
            $table->longText('usage')->nullable()->after('additional_description');
        });

        DB::table('products')->update([
            'specifications' => json_encode([
                ['label' => 'برند', 'value' => 'اطلاعات درج‌شده روی بسته‌بندی'],
                ['label' => 'کشور سازنده', 'value' => 'مطابق مشخصات تولیدکننده'],
                ['label' => 'نوع محصول', 'value' => 'محصول سلامت و مراقبت'],
                ['label' => 'شرایط نگهداری', 'value' => 'در جای خشک، خنک و دور از نور مستقیم'],
            ], JSON_UNESCAPED_UNICODE),
            'additional_description' => 'این محصول با تضمین اصالت و سلامت فیزیکی کالا عرضه می‌شود. پیش از مصرف، اطلاعات روی بسته‌بندی و توصیه‌های تولیدکننده را به‌دقت مطالعه کنید.',
            'usage' => 'مقدار و زمان مصرف را مطابق دستور درج‌شده روی بسته‌بندی یا توصیه پزشک و داروساز تنظیم کنید. از مصرف بیش از مقدار توصیه‌شده خودداری شود.',
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['specifications', 'additional_description', 'usage']);
        });
    }
};
