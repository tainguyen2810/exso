<?php

use Illuminate\Support\Facades\Route;
use Modules\Promotion\Http\Controllers\CouponController;
use Modules\Promotion\Http\Controllers\LoyaltyController;
use Modules\Promotion\Http\Controllers\PromotionController;

// Ứng dụng → Khuyến mại.
Route::get('promotions', [PromotionController::class, 'index'])->middleware('can:promotions.view')->name('promotions.index');
Route::get('promotions/settings', [PromotionController::class, 'settings'])->middleware('can:promotions.view')->name('promotions.settings');
Route::put('promotions/settings', [PromotionController::class, 'saveSettings'])->middleware('can:promotions.update')->name('promotions.settings.update');
Route::get('promotions/create', [PromotionController::class, 'create'])->middleware('can:promotions.create')->name('promotions.create');
Route::post('promotions', [PromotionController::class, 'store'])->middleware('can:promotions.create')->name('promotions.store');
Route::get('promotions/{promotion}', [PromotionController::class, 'show'])->middleware('can:promotions.view')->name('promotions.show');
Route::put('promotions/{promotion}', [PromotionController::class, 'update'])->middleware('can:promotions.update')->name('promotions.update');
Route::post('promotions/{promotion}/status', [PromotionController::class, 'status'])->middleware('can:promotions.update')->name('promotions.status');
Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->middleware('can:promotions.delete')->name('promotions.destroy');

Route::get('coupons', [CouponController::class, 'index'])->middleware('can:coupons.view')->name('coupons.index');
Route::get('coupons/create', [CouponController::class, 'create'])->middleware('can:coupons.create')->name('coupons.create');
Route::post('coupons', [CouponController::class, 'store'])->middleware('can:coupons.create')->name('coupons.store');
Route::get('coupons/{batch}', [CouponController::class, 'show'])->middleware('can:coupons.view')->name('coupons.show');
Route::put('coupons/{batch}', [CouponController::class, 'update'])->middleware('can:coupons.update')->name('coupons.update');
Route::delete('coupons/{batch}', [CouponController::class, 'destroy'])->middleware('can:coupons.delete')->name('coupons.destroy');
Route::post('coupons/{batch}/codes', [CouponController::class, 'addCodes'])->middleware('can:coupons.update')->name('coupons.codes.store');
Route::put('coupons/{batch}/codes/{coupon}', [CouponController::class, 'toggleCode'])->middleware('can:coupons.update')->name('coupons.codes.update');
Route::delete('coupons/{batch}/codes/{coupon}', [CouponController::class, 'destroyCode'])->middleware('can:coupons.update')->name('coupons.codes.destroy');

// Loyalty: tích điểm, hạng thành viên, lịch sử điểm.
Route::get('loyalty', [LoyaltyController::class, 'index'])->middleware('can:loyalty.view')->name('loyalty.index');
Route::put('loyalty', [LoyaltyController::class, 'saveSettings'])->middleware('can:loyalty.update')->name('loyalty.update');
Route::post('loyalty/tiers', [LoyaltyController::class, 'storeTier'])->middleware('can:loyalty.update')->name('loyalty.tiers.store');
Route::put('loyalty/tiers/{tier}', [LoyaltyController::class, 'updateTier'])->middleware('can:loyalty.update')->name('loyalty.tiers.update');
Route::delete('loyalty/tiers/{tier}', [LoyaltyController::class, 'destroyTier'])->middleware('can:loyalty.update')->name('loyalty.tiers.destroy');
Route::get('loyalty/points', [LoyaltyController::class, 'points'])->middleware('can:loyalty.view')->name('loyalty.points');
Route::post('loyalty/points', [LoyaltyController::class, 'adjust'])->middleware('can:loyalty.update')->name('loyalty.points.adjust');
