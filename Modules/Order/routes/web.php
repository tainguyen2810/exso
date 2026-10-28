<?php

use Illuminate\Support\Facades\Route;
use Modules\Order\Http\Controllers\OrderController;
use Modules\Order\Http\Controllers\PosController;
use Modules\Order\Http\Controllers\ReturnController;

Route::get('orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('orders.index');
Route::get('orders/variants/search', [OrderController::class, 'searchVariants'])->name('orders.variants.search');
Route::get('orders/variants/prices', [OrderController::class, 'reprice'])->name('orders.variants.prices');
// "Áp dụng chương trình khuyến mại" of the order form and POS (same result for both).
Route::post('orders/promotions', [OrderController::class, 'promotions'])->name('orders.promotions');
Route::post('orders/bulk', [OrderController::class, 'bulk'])->middleware('can:orders.view')->name('orders.bulk');
Route::get('orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('orders.create');
Route::post('orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store');
Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->name('orders.show');
Route::get('orders/{order}/edit', [OrderController::class, 'edit'])->middleware('can:orders.update')->name('orders.edit');
Route::put('orders/{order}', [OrderController::class, 'update'])->middleware('can:orders.update')->name('orders.update');
Route::get('orders/{order}/print', [OrderController::class, 'print'])->middleware('can:orders.view')->name('orders.print');
Route::post('orders/{order}/pay', [OrderController::class, 'pay'])->middleware('can:orders.pay')->name('orders.pay');
Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('can:orders.cancel')->name('orders.cancel');
Route::post('orders/{order}/{action}', [OrderController::class, 'step'])
    ->whereIn('action', ['approve', 'pack', 'export', 'deliver', 'delivery-failed', 'receive-back'])
    ->middleware('can:orders.view')
    ->name('orders.step');

Route::get('returns', [ReturnController::class, 'index'])->middleware('can:returns.view')->name('returns.index');
Route::get('returns/create', [ReturnController::class, 'create'])->middleware('can:returns.create')->name('returns.create');
Route::post('returns', [ReturnController::class, 'store'])->middleware('can:returns.create')->name('returns.store');
Route::get('returns/{return}', [ReturnController::class, 'show'])->middleware('can:returns.view')->name('returns.show');
Route::post('returns/{return}/refund', [ReturnController::class, 'refund'])->middleware('can:returns.pay')->name('returns.refund');

Route::get('pos', [PosController::class, 'index'])->middleware('can:pos.view')->name('pos.index');
Route::get('pos/qr', [PosController::class, 'qr'])->middleware('can:pos.view')->name('pos.qr');
Route::get('pos/recent', [PosController::class, 'recent'])->middleware('can:pos.view')->name('pos.recent');
Route::post('pos/shifts', [PosController::class, 'openShift'])->middleware('can:pos.create')->name('pos.shifts.open');
Route::post('pos/shifts/{shift}/close', [PosController::class, 'closeShift'])->middleware('can:pos.create')->name('pos.shifts.close');
Route::post('pos/orders', [PosController::class, 'store'])->middleware('can:pos.create')->name('pos.orders.store');
