<?php

use Illuminate\Support\Facades\Route;
use Modules\Einvoice\Http\Controllers\EinvoiceController;
use Modules\Einvoice\Http\Controllers\ProviderController;

// Đơn hàng → Hóa đơn điện tử.
Route::get('e-invoices', [EinvoiceController::class, 'index'])->middleware('can:einvoices.view')->name('e-invoices.index');
Route::get('e-invoices/pending', [EinvoiceController::class, 'pending'])->middleware('can:einvoices.view')->name('e-invoices.pending');
Route::post('e-invoices/pending/bulk', [EinvoiceController::class, 'bulkOrders'])->middleware(['can:einvoices.create', 'throttle:20,1'])->name('e-invoices.pending.bulk');
Route::post('e-invoices/orders/{order}', [EinvoiceController::class, 'storeForOrder'])->middleware('can:einvoices.create')->name('e-invoices.orders.store');
Route::post('e-invoices/bulk', [EinvoiceController::class, 'bulk'])->middleware(['can:einvoices.approve', 'throttle:20,1'])->name('e-invoices.bulk');

// Cấu hình → Hóa đơn điện tử.
Route::get('e-invoices/settings', [ProviderController::class, 'index'])->middleware('can:settings.view')->name('e-invoices.settings');
Route::put('e-invoices/settings', [ProviderController::class, 'saveSettings'])->middleware('can:settings.update')->name('e-invoices.settings.update');
Route::post('e-invoices/providers/{driver}/verify', [ProviderController::class, 'verify'])->middleware(['can:settings.update', 'throttle:20,1'])->name('e-invoices.providers.verify');
Route::put('e-invoices/providers/{driver}', [ProviderController::class, 'connect'])->middleware(['can:settings.update', 'throttle:20,1'])->name('e-invoices.providers.connect');
Route::delete('e-invoices/providers/{driver}', [ProviderController::class, 'disconnect'])->middleware('can:settings.update')->name('e-invoices.providers.disconnect');

Route::get('e-invoices/{einvoice}', [EinvoiceController::class, 'show'])->middleware('can:einvoices.view')->name('e-invoices.show');
Route::put('e-invoices/{einvoice}', [EinvoiceController::class, 'update'])->middleware('can:einvoices.create')->name('e-invoices.update');
Route::post('e-invoices/{einvoice}/issue', [EinvoiceController::class, 'issue'])->middleware(['can:einvoices.approve', 'throttle:30,1'])->name('e-invoices.issue');
Route::post('e-invoices/{einvoice}/cancel', [EinvoiceController::class, 'cancel'])->middleware(['can:einvoices.cancel', 'throttle:30,1'])->name('e-invoices.cancel');
Route::post('e-invoices/{einvoice}/adjust', [EinvoiceController::class, 'adjust'])->middleware('can:einvoices.create')->name('e-invoices.adjust');
Route::get('e-invoices/{einvoice}/pdf', [EinvoiceController::class, 'pdf'])->middleware(['can:einvoices.view', 'throttle:30,1'])->name('e-invoices.pdf');
