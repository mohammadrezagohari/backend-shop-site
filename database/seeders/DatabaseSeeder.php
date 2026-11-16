<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Content\Domain\Models\Post;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'مدیر گلدن اید',
            'email' => env('ADMIN_EMAIL', 'admin@goldenaid.test'),
            'password' => env('ADMIN_PASSWORD', 'password'),
            'is_admin' => true,
        ]);

        $categories = collect([
            ['name' => 'مکمل‌های ورزشی', 'slug' => 'sports-supplements'],
            ['name' => 'مراقبت پوست و مو', 'slug' => 'skin-hair'],
            ['name' => 'تجهیزات پزشکی', 'slug' => 'medical-equipment'],
            ['name' => 'ویتامین و سلامت', 'slug' => 'vitamins'],
        ])->mapWithKeys(fn (array $item, int $index) => [$item['slug'] => Category::query()->create($item + ['sort_order' => $index])]);

        $products = [
            ['sports-supplements', 'پودر کراتین گلد کر لاین ترک نوتریشن', 'gold-creatine', 'GA-1001', 1254600, 1330000, true, '/images/product-1.png'],
            ['vitamins', 'سافت ژل بیوتی بست نوتری بست', 'beauty-best', 'GA-1002', 840000, null, true, '/images/product-2.png'],
            ['skin-hair', 'کرم مرطوب کننده و روشن کننده ویتامین C', 'vitamin-c-cream', 'GA-1003', 625000, 690000, true, '/images/product-3.png'],
            ['sports-supplements', 'پودر وی پروتئین دوبیس', 'doobis-whey', 'GA-1004', 2890000, 3100000, true, '/images/product-4.png'],
            ['medical-equipment', 'فشارسنج دیجیتال بازویی', 'digital-pressure', 'GA-1005', 2150000, null, false, '/images/product-5.png'],
            ['vitamins', 'مولتی ویتامین مینرال روزانه', 'daily-multivitamin', 'GA-1006', 475000, null, false, '/images/product-6.png'],
        ];
        foreach ($products as [$category, $name, $slug, $sku, $price, $oldPrice, $featured, $image]) {
            Product::query()->create([
                'category_id' => $categories[$category]->id,
                'name' => $name, 'slug' => $slug, 'sku' => $sku,
                'summary' => 'محصول اصل با ضمانت سلامت و اصالت کالا، ارسال سریع از گلدن اید.',
                'description' => 'اطلاعات کامل مصرف و ویژگی‌های محصول پس از بررسی کارشناسان گلدن اید ارائه شده است.',
                'specifications' => [
                    ['label' => 'برند', 'value' => 'اطلاعات درج‌شده روی بسته‌بندی'],
                    ['label' => 'کشور سازنده', 'value' => 'مطابق مشخصات تولیدکننده'],
                    ['label' => 'نوع محصول', 'value' => 'محصول سلامت و مراقبت'],
                    ['label' => 'شرایط نگهداری', 'value' => 'در جای خشک، خنک و دور از نور مستقیم'],
                ],
                'additional_description' => 'این محصول با تضمین اصالت و سلامت فیزیکی کالا عرضه می‌شود. پیش از مصرف، اطلاعات روی بسته‌بندی و توصیه‌های تولیدکننده را به‌دقت مطالعه کنید.',
                'usage' => 'مقدار و زمان مصرف را مطابق دستور درج‌شده روی بسته‌بندی یا توصیه پزشک و داروساز تنظیم کنید. از مصرف بیش از مقدار توصیه‌شده خودداری شود.',
                'price' => $price, 'old_price' => $oldPrice, 'stock' => 24,
                'rating' => 4.6, 'image' => $image, 'is_featured' => $featured, 'is_active' => true,
            ]);
        }

        collect([
            ['راهنمای انتخاب دستگاه فشار خون خانگی', 'blood-pressure-guide', '/images/figma-blog-feature.png'],
            ['فواید روزه برای سلامتی و کنترل قند خون', 'fasting-health-benefits', '/images/product-5.png'],
            ['راه‌های ساده پیشگیری از بیماری‌های قلبی', 'heart-disease-prevention', '/images/product-6.png'],
        ])->each(fn (array $post) => Post::query()->create([
            'title' => $post[0], 'slug' => $post[1], 'image' => $post[2],
            'excerpt' => 'مطالب علمی و کاربردی برای انتخاب آگاهانه و داشتن سبک زندگی سالم‌تر.',
            'body' => 'این مقاله توسط تیم محتوای گلدن اید برای افزایش آگاهی کاربران تهیه شده است.',
            'published_at' => now(),
        ]));
        $this->call(CatalogEnrichmentSeeder::class);
    }
}
