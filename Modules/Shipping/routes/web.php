<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipping\Http\Controllers\CarrierController;
use Modules\Shipping\Http\Controllers\CodReconciliationController;
use Modules\Shipping\Http\Controllers\ShipmentController;
use Modules\Shipping\Http\Controllers\ShippingController;

// Vận chuyển → Tổng quan, Cấu hình giao hàng.
Route::get('shipping', [ShippingController::class, 'overview'])->middleware('can:shipments.view')->name('shipping.index');
Route::get('shipping/settings', [ShippingController::class, 'settings'])->middleware('can:settings.view')->name('shipping.settings');
Route::put('shipping/settings', [ShippingController::class, 'saveSettings'])->middleware('can:settings.update')->name('shipping.settings.update');
// Fees of the connected carriers, for the order form and the "Giao hàng" dialog.
Route::post('shipping/quotes', [ShipmentController::class, 'quotes'])->name('shipping.quotes');

// Kết nối đối tác.
Route::get('shipping/partners', [CarrierController::class, 'index'])->middleware('can:carriers.view')->name('shipping.partners');
Route::post('shipping/partners', [CarrierController::class, 'store'])->middleware('can:carriers.create')->name('shipping.partners.store');
Route::put('shipping/partners/manual/{carrier}', [CarrierController::class, 'update'])->middleware('can:carriers.update')->name('shipping.partners.update');
Route::delete('shipping/partners/manual/{carrier}', [CarrierController::class, 'destroy'])->middleware('can:carriers.delete')->name('shipping.partners.destroy');
Route::post('shipping/partners/{driver}/verify', [CarrierController::class, 'verify'])->middleware(['can:carriers.update', 'throttle:20,1'])->name('shipping.partners.verify');
Route::put('shipping/partners/{driver}', [CarrierController::class, 'connect'])->middleware(['can:carriers.update', 'throttle:20,1'])->name('shipping.partners.connect');
Route::post('shipping/partners/{driver}/webhook', [CarrierController::class, 'rotateWebhook'])->middleware('can:carriers.update')->name('shipping.partners.webhook');

// Quản lý vận đơn.
Route::get('shipments', [ShipmentController::class, 'index'])->middleware('can:shipments.view')->name('shipments.index');
Route::get('shipments/search', [ShipmentController::class, 'search'])->name('shipments.search');
Route::post('shipments', [ShipmentController::class, 'store'])->middleware(['can:shipments.create', 'throttle:60,1'])->name('shipments.store');
Route::get('shipments/{shipment}', [ShipmentController::class, 'show'])->middleware('can:shipments.view')->name('shipments.show');
Route::put('shipments/{shipment}', [ShipmentController::class, 'update'])->middleware('can:shipments.update')->name('shipments.update');
Route::post('shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])->middleware('can:shipments.cancel')->name('shipments.cancel');
Route::post('shipments/{shipment}/status', [ShipmentController::class, 'status'])->middleware('can:shipments.update')->name('shipments.status');
Route::post('shipments/{shipment}/sync', [ShipmentController::class, 'sync'])->middleware(['can:shipments.view', 'throttle:30,1'])->name('shipments.sync');
Route::get('shipments/{shipment}/print', [ShipmentController::class, 'print'])->middleware('can:shipments.view')->name('shipments.print');
Route::get('shipments/{shipment}/label', [ShipmentController::class, 'label'])->middleware('can:shipments.view')->name('shipments.label');

// Đối soát COD và phí.
Route::get('cod-reconciliations', [CodReconciliationController::class, 'index'])->middleware('can:cod_reconciliations.view')->name('cod-reconciliations.index');
Route::get('cod-reconciliations/create', [CodReconciliationController::class, 'create'])->middleware('can:cod_reconciliations.create')->name('cod-reconciliations.create');
Route::post('cod-reconciliations', [CodReconciliationController::class, 'store'])->middleware('can:cod_reconciliations.create')->name('cod-reconciliations.store');
Route::get('cod-reconciliations/{reconciliation}', [CodReconciliationController::class, 'show'])->middleware('can:cod_reconciliations.view')->name('cod-reconciliations.show');
Route::put('cod-reconciliations/{reconciliation}/items/{item}', [CodReconciliationController::class, 'updateItem'])->middleware('can:cod_reconciliations.create')->name('cod-reconciliations.items.update');
Route::post('cod-reconciliations/{reconciliation}/complete', [CodReconciliationController::class, 'complete'])->middleware('can:cod_reconciliations.approve')->name('cod-reconciliations.complete');
Route::post('cod-reconciliations/{reconciliation}/cancel', [CodReconciliationController::class, 'cancel'])->middleware('can:cod_reconciliations.cancel')->name('cod-reconciliations.cancel');
