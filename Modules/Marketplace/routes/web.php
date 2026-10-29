<?php

use Illuminate\Support\Facades\Route;
use Modules\Marketplace\Http\Controllers\ListingController;
use Modules\Marketplace\Http\Controllers\MarketplaceController;
use Modules\Marketplace\Http\Controllers\ShopController;

// Sàn TMĐT → Tổng quan, Đơn hàng.
Route::get('marketplaces', [MarketplaceController::class, 'overview'])->middleware('can:marketplaces.view')->name('marketplaces.index');
Route::get('marketplaces/orders', [MarketplaceController::class, 'orders'])->middleware('can:marketplaces.view')->name('marketplaces.orders');
Route::post('marketplaces/orders/pull', [MarketplaceController::class, 'pull'])->middleware(['can:marketplaces.update', 'throttle:10,1'])->name('marketplaces.orders.pull');
Route::post('marketplaces/orders/{marketplaceOrder}/sync', [MarketplaceController::class, 'resync'])->middleware(['can:marketplaces.update', 'throttle:60,1'])->name('marketplaces.orders.sync');

// Sản phẩm: liên kết sản phẩm sàn ↔ phiên bản.
Route::get('marketplaces/products', [ListingController::class, 'index'])->middleware('can:marketplaces.view')->name('marketplaces.products');
Route::post('marketplaces/products/fetch', [ListingController::class, 'fetch'])->middleware(['can:marketplaces.update', 'throttle:10,1'])->name('marketplaces.products.fetch');
Route::post('marketplaces/products/auto-link', [ListingController::class, 'autoLink'])->middleware('can:marketplaces.update')->name('marketplaces.products.auto-link');
Route::post('marketplaces/products/push-stock', [ListingController::class, 'pushStock'])->middleware(['can:marketplaces.update', 'throttle:10,1'])->name('marketplaces.products.push-stock');
Route::put('marketplaces/products/{listing}/link', [ListingController::class, 'link'])->middleware('can:marketplaces.update')->name('marketplaces.products.link');
Route::delete('marketplaces/products/{listing}/link', [ListingController::class, 'unlink'])->middleware('can:marketplaces.update')->name('marketplaces.products.unlink');

// Cấu hình: kết nối gian hàng, cấu hình riêng từng gian hàng.
Route::get('marketplaces/settings', [ShopController::class, 'index'])->middleware('can:marketplaces.view')->name('marketplaces.settings');
Route::post('marketplaces/shops', [ShopController::class, 'store'])->middleware(['can:marketplaces.create', 'throttle:20,1'])->name('marketplaces.shops.store');
Route::get('marketplaces/shops/{shop}/callback', [ShopController::class, 'callback'])->middleware(['can:marketplaces.create', 'throttle:20,1'])->name('marketplaces.shops.callback');
Route::get('marketplaces/shops/{shop}', [ShopController::class, 'show'])->middleware('can:marketplaces.view')->name('marketplaces.shops.show');
Route::put('marketplaces/shops/{shop}', [ShopController::class, 'update'])->middleware('can:marketplaces.update')->name('marketplaces.shops.update');
Route::post('marketplaces/shops/{shop}/authorize', [ShopController::class, 'reauthorize'])->middleware(['can:marketplaces.update', 'throttle:20,1'])->name('marketplaces.shops.authorize');
Route::post('marketplaces/shops/{shop}/webhook', [ShopController::class, 'rotateWebhook'])->middleware('can:marketplaces.update')->name('marketplaces.shops.webhook');
Route::post('marketplaces/shops/{shop}/disconnect', [ShopController::class, 'disconnect'])->middleware('can:marketplaces.delete')->name('marketplaces.shops.disconnect');
Route::delete('marketplaces/shops/{shop}', [ShopController::class, 'destroy'])->middleware('can:marketplaces.delete')->name('marketplaces.shops.destroy');
