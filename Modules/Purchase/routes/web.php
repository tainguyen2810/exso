<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchase\Http\Controllers\PurchaseOrderController;
use Modules\Purchase\Http\Controllers\PurchaseReceiptController;
use Modules\Purchase\Http\Controllers\SupplierController;

Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:suppliers.view')->name('suppliers.index');
Route::get('suppliers/search', [SupplierController::class, 'search'])->middleware('can:purchases.view')->name('suppliers.search');
Route::post('suppliers/bulk', [SupplierController::class, 'bulk'])->middleware('can:suppliers.view')->name('suppliers.bulk');
Route::get('suppliers/create', [SupplierController::class, 'create'])->middleware('can:suppliers.create')->name('suppliers.create');
Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.create')->name('suppliers.store');
Route::get('suppliers/{supplier}', [SupplierController::class, 'edit'])->middleware('can:suppliers.view')->name('suppliers.edit');
Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.update')->name('suppliers.update');
Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('can:suppliers.delete')->name('suppliers.destroy');

Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('can:purchases.view')->name('purchase-orders.index');
Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->middleware('can:purchases.create')->name('purchase-orders.create');
Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('can:purchases.create')->name('purchase-orders.store');
Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->middleware('can:purchases.view')->name('purchase-orders.show');
Route::get('purchase-orders/{order}/edit', [PurchaseOrderController::class, 'edit'])->middleware('can:purchases.update')->name('purchase-orders.edit');
Route::put('purchase-orders/{order}', [PurchaseOrderController::class, 'update'])->middleware('can:purchases.update')->name('purchase-orders.update');
Route::post('purchase-orders/{order}/close', [PurchaseOrderController::class, 'close'])->middleware('can:purchases.cancel')->name('purchase-orders.close');

Route::get('purchase-receipts', [PurchaseReceiptController::class, 'index'])->middleware('can:purchases.view')->name('purchase-receipts.index');
Route::get('purchase-receipts/create', [PurchaseReceiptController::class, 'create'])->middleware('can:purchases.create')->name('purchase-receipts.create');
Route::post('purchase-receipts', [PurchaseReceiptController::class, 'store'])->middleware('can:purchases.create')->name('purchase-receipts.store');
Route::get('purchase-receipts/{receipt}', [PurchaseReceiptController::class, 'show'])->middleware('can:purchases.view')->name('purchase-receipts.show');
Route::get('purchase-receipts/{receipt}/edit', [PurchaseReceiptController::class, 'edit'])->middleware('can:purchases.update')->name('purchase-receipts.edit');
Route::put('purchase-receipts/{receipt}', [PurchaseReceiptController::class, 'update'])->middleware('can:purchases.update')->name('purchase-receipts.update');
Route::post('purchase-receipts/{receipt}/receive', [PurchaseReceiptController::class, 'receive'])->middleware('can:purchases.approve')->name('purchase-receipts.receive');
Route::post('purchase-receipts/{receipt}/pay', [PurchaseReceiptController::class, 'pay'])->middleware('can:purchases.pay')->name('purchase-receipts.pay');
Route::post('purchase-receipts/{receipt}/cancel', [PurchaseReceiptController::class, 'cancel'])->middleware('can:purchases.cancel')->name('purchase-receipts.cancel');
Route::post('purchase-receipts/{receipt}/return', [PurchaseReceiptController::class, 'returnGoods'])->middleware('can:purchases.approve')->name('purchase-receipts.return');
