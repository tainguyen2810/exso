<?php

use Illuminate\Support\Facades\Route;
use Modules\Customer\Http\Controllers\CustomerController;
use Modules\Customer\Http\Controllers\CustomerGroupController;

Route::get('customers', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.index');
Route::get('customers/search', [CustomerController::class, 'search'])->middleware('can:customers.view')->name('customers.search');
Route::post('customers/bulk', [CustomerController::class, 'bulk'])->middleware('can:customers.view')->name('customers.bulk');
Route::get('customers/import/template', [CustomerController::class, 'template'])->middleware('can:customers.import')->name('customers.import.template');
Route::post('customers/import', [CustomerController::class, 'import'])->middleware('can:customers.import')->name('customers.import');
Route::get('customers/create', [CustomerController::class, 'create'])->middleware('can:customers.create')->name('customers.create');
Route::post('customers', [CustomerController::class, 'store'])->middleware('can:customers.create')->name('customers.store');
Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view')->name('customers.show');
Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware('can:customers.update')->name('customers.edit');
Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.update')->name('customers.update');
Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('can:customers.delete')->name('customers.destroy');
Route::post('customers/{customer}/notes', [CustomerController::class, 'addNote'])->middleware('can:customers.update')->name('customers.notes.store');
Route::delete('customers/{customer}/notes/{note}', [CustomerController::class, 'deleteNote'])->middleware('can:customers.update')->name('customers.notes.destroy');
Route::post('customers/{customer}/addresses', [CustomerController::class, 'saveAddress'])->middleware('can:customers.update')->name('customers.addresses.store');
Route::put('customers/{customer}/addresses/{address}', [CustomerController::class, 'saveAddress'])->middleware('can:customers.update')->name('customers.addresses.update');
Route::delete('customers/{customer}/addresses/{address}', [CustomerController::class, 'deleteAddress'])->middleware('can:customers.update')->name('customers.addresses.destroy');
Route::post('customers/{customer}/contacts', [CustomerController::class, 'saveContact'])->middleware('can:customers.update')->name('customers.contacts.store');
Route::put('customers/{customer}/contacts/{contact}', [CustomerController::class, 'saveContact'])->middleware('can:customers.update')->name('customers.contacts.update');
Route::delete('customers/{customer}/contacts/{contact}', [CustomerController::class, 'deleteContact'])->middleware('can:customers.update')->name('customers.contacts.destroy');

Route::get('customer-groups', [CustomerGroupController::class, 'index'])->middleware('can:customers.view')->name('customer-groups.index');
Route::get('customer-groups/create', [CustomerGroupController::class, 'create'])->middleware('can:customers.update')->name('customer-groups.create');
Route::post('customer-groups', [CustomerGroupController::class, 'store'])->middleware('can:customers.update')->name('customer-groups.store');
Route::get('customer-groups/{customerGroup}/edit', [CustomerGroupController::class, 'edit'])->middleware('can:customers.update')->name('customer-groups.edit');
Route::put('customer-groups/{customerGroup}', [CustomerGroupController::class, 'update'])->middleware('can:customers.update')->name('customer-groups.update');
Route::delete('customer-groups/{customerGroup}', [CustomerGroupController::class, 'destroy'])->middleware('can:customers.update')->name('customer-groups.destroy');
