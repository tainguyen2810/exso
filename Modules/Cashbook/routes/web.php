<?php

use Illuminate\Support\Facades\Route;
use Modules\Cashbook\Http\Controllers\CashbookController;
use Modules\Cashbook\Http\Controllers\VoucherController;
use Modules\Cashbook\Http\Controllers\VoucherTypeController;

Route::get('cashbook', [CashbookController::class, 'index'])->middleware('can:cashbook.view')->name('cashbook.index');

Route::get('cashbook/receipts', [VoucherController::class, 'receipts'])->middleware('can:cashbook.view')->name('cashbook.receipts');
Route::get('cashbook/payments', [VoucherController::class, 'payments'])->middleware('can:cashbook.view')->name('cashbook.payments');
Route::get('cashbook/receipts/create', [VoucherController::class, 'createReceipt'])->middleware('can:cashbook.create')->name('cashbook.receipts.create');
Route::get('cashbook/payments/create', [VoucherController::class, 'createPayment'])->middleware('can:cashbook.create')->name('cashbook.payments.create');
Route::post('cashbook/vouchers', [VoucherController::class, 'store'])->middleware('can:cashbook.create')->name('cashbook.vouchers.store');
Route::get('cashbook/vouchers/{voucher}', [VoucherController::class, 'show'])->middleware('can:cashbook.view')->name('cashbook.vouchers.show');
Route::get('cashbook/vouchers/{voucher}/print', [VoucherController::class, 'print'])->middleware('can:cashbook.view')->name('cashbook.vouchers.print');
Route::put('cashbook/vouchers/{voucher}', [VoucherController::class, 'update'])->middleware('can:cashbook.update')->name('cashbook.vouchers.update');
Route::post('cashbook/vouchers/{voucher}/cancel', [VoucherController::class, 'cancel'])->middleware('can:cashbook.cancel')->name('cashbook.vouchers.cancel');

Route::get('cashbook/receipt-types', [VoucherTypeController::class, 'receipts'])->middleware('can:cashbook.view')->name('cashbook.receipt-types');
Route::get('cashbook/payment-types', [VoucherTypeController::class, 'payments'])->middleware('can:cashbook.view')->name('cashbook.payment-types');
Route::post('cashbook/types', [VoucherTypeController::class, 'store'])->middleware('can:cashbook.update')->name('cashbook.types.store');
Route::put('cashbook/types/{type}', [VoucherTypeController::class, 'update'])->middleware('can:cashbook.update')->name('cashbook.types.update');
Route::delete('cashbook/types/{type}', [VoucherTypeController::class, 'destroy'])->middleware('can:cashbook.delete')->name('cashbook.types.destroy');
