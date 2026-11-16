<?php

use App\Modules\Catalog\Presentation\Http\Controllers\AdminCatalogController;
use App\Modules\Catalog\Presentation\Http\Controllers\CatalogController;
use App\Modules\Catalog\Presentation\Http\Controllers\ProductCommentController;
use App\Modules\Content\Presentation\Http\Controllers\AdminContentController;
use App\Modules\Content\Presentation\Http\Controllers\PostController;
use App\Modules\Content\Presentation\Http\Controllers\SiteBadgeController;
use App\Modules\Identity\Presentation\Http\Controllers\AdminUserController;
use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use App\Modules\Ordering\Presentation\Http\Controllers\AdminOrderController;
use App\Modules\Ordering\Presentation\Http\Controllers\CartController;
use App\Modules\Ordering\Presentation\Http\Controllers\OrderController;
use App\Modules\Ordering\Presentation\Http\Controllers\TestPaymentController;
use App\Modules\Ordering\Presentation\Http\Controllers\PaymentController;
use App\Modules\Reporting\Presentation\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use App\Modules\Marketplaces\Presentation\Http\Controllers\MarketplaceController;
use App\Modules\Marketplaces\Presentation\Http\Controllers\TorobController;

Route::prefix('v1')->group(function (): void {
    Route::post('marketplaces/torob/products', TorobController::class)->middleware('throttle:120,1');
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('site-badges', [SiteBadgeController::class, 'index']);
    Route::get('products', [CatalogController::class, 'index']);
    Route::get('products/{product:slug}', [CatalogController::class, 'show']);
    Route::get('products/{product:slug}/comments', [ProductCommentController::class, 'index']);
    Route::post('products/{product:slug}/comments', [ProductCommentController::class, 'store']);
    Route::get('posts', [PostController::class, 'index']);
    Route::get('posts/{post:slug}', [PostController::class, 'show']);
    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/items', [CartController::class, 'store']);
    Route::patch('cart/items/{item}', [CartController::class, 'update']);
    Route::delete('cart/items/{item}', [CartController::class, 'destroy']);

    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::middleware('auth.api')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });

    Route::post('orders', [OrderController::class, 'store'])->middleware('auth.api:optional');
    Route::post('orders/track', [OrderController::class, 'track'])->middleware('throttle:20,1');
    Route::get('orders/mine', [OrderController::class, 'mine'])->middleware('auth.api');
    Route::get('orders/{order:public_id}', [OrderController::class, 'show']);
    Route::post('orders/{order:public_id}/payments/test', [TestPaymentController::class, 'start']);
    Route::post('orders/{order:public_id}/payments', [PaymentController::class, 'start'])->middleware('throttle:20,1');
    Route::get('payments/zarinpal/callback', [PaymentController::class, 'callback'])->name('payments.zarinpal.callback');
    Route::post('orders/{order:public_id}/payments/test/verify', [TestPaymentController::class, 'verify']);
    Route::prefix('admin')->middleware(['auth.api', 'admin'])->group(function (): void {
        Route::get('marketplaces', [MarketplaceController::class, 'index']);
        Route::post('products/{product}/sync', [MarketplaceController::class, 'sync']);
        Route::get('site-badges', [SiteBadgeController::class, 'adminIndex']);
        Route::post('site-badges', [SiteBadgeController::class, 'store']);
        Route::patch('site-badges/{badge}', [SiteBadgeController::class, 'update']);
        Route::delete('site-badges/{badge}', [SiteBadgeController::class, 'destroy']);
        Route::get('dashboard', DashboardController::class);
        Route::get('products', [AdminCatalogController::class, 'products']);
        Route::post('products', [AdminCatalogController::class, 'storeProduct']);
        Route::match(['put', 'patch'], 'products/{product}', [AdminCatalogController::class, 'updateProduct']);
        Route::delete('products/{product}', [AdminCatalogController::class, 'destroyProduct']);
        Route::get('categories', [AdminCatalogController::class, 'categories']);
        Route::post('categories', [AdminCatalogController::class, 'storeCategory']);
        Route::match(['put', 'patch'], 'categories/{category}', [AdminCatalogController::class, 'updateCategory']);
        Route::delete('categories/{category}', [AdminCatalogController::class, 'destroyCategory']);
        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::get('orders/{order}', [AdminOrderController::class, 'show']);
        Route::patch('orders/{order}', [AdminOrderController::class, 'update']);
        Route::get('users', [AdminUserController::class, 'index']);
        Route::patch('users/{user}', [AdminUserController::class, 'update']);
        Route::get('posts', [AdminContentController::class, 'posts']);
        Route::post('posts', [AdminContentController::class, 'storePost']);
        Route::match(['put', 'patch'], 'posts/{post}', [AdminContentController::class, 'updatePost']);
        Route::delete('posts/{post}', [AdminContentController::class, 'destroyPost']);
        Route::get('comments', [AdminContentController::class, 'comments']);
        Route::patch('comments/{comment}', [AdminContentController::class, 'updateComment']);
        Route::delete('comments/{comment}', [AdminContentController::class, 'destroyComment']);
    });
});
