<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\BarcodeController;
use Modules\Product\Http\Controllers\CatalogListController;
use Modules\Product\Http\Controllers\PriceListController;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\ProductImportController;

Route::get('products', [ProductController::class, 'index'])->middleware('can:products.view')->name('products.index');
Route::post('products/bulk', [ProductController::class, 'bulk'])->middleware('can:products.update')->name('products.bulk');
Route::get('products/import/template', [ProductImportController::class, 'template'])->middleware('can:products.create')->name('products.import.template');
Route::post('products/import', [ProductImportController::class, 'store'])->middleware('can:products.create')->name('products.import');
Route::get('products/variants/search', [ProductController::class, 'searchVariants'])->middleware('can:products.view')->name('products.variants.search');
Route::get('products/barcodes', [BarcodeController::class, 'index'])->middleware('can:products.view')->name('products.barcodes');
Route::get('products/create', [ProductController::class, 'create'])->middleware('can:products.create')->name('products.create');
Route::post('products', [ProductController::class, 'store'])->middleware('can:products.create')->name('products.store');
Route::get('products/{product}/edit', [ProductController::class, 'edit'])->middleware('can:products.view')->name('products.edit');
Route::put('products/{product}', [ProductController::class, 'update'])->middleware('can:products.update')->name('products.update');
Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('can:products.delete')->name('products.destroy');

Route::get('product-lists/{list}', [CatalogListController::class, 'index'])->middleware('can:products.view')->whereIn('list', ['categories', 'brands'])->name('product-lists.index');
Route::post('product-lists/{list}', [CatalogListController::class, 'store'])->middleware('can:products.create')->whereIn('list', ['categories', 'brands'])->name('product-lists.store');
Route::put('product-lists/{list}/{id}', [CatalogListController::class, 'update'])->middleware('can:products.update')->whereIn('list', ['categories', 'brands'])->name('product-lists.update');
Route::delete('product-lists/{list}/{id}', [CatalogListController::class, 'destroy'])->middleware('can:products.delete')->whereIn('list', ['categories', 'brands'])->name('product-lists.destroy');

Route::get('price-lists', [PriceListController::class, 'index'])->middleware('can:products.view')->name('price-lists.index');
Route::post('price-lists', [PriceListController::class, 'store'])->middleware('can:products.create')->name('price-lists.store');
Route::get('price-lists/{priceList}', [PriceListController::class, 'show'])->middleware('can:products.view')->name('price-lists.show');
Route::put('price-lists/{priceList}', [PriceListController::class, 'update'])->middleware('can:products.update')->name('price-lists.update');
Route::put('price-lists/{priceList}/prices', [PriceListController::class, 'setPrice'])->middleware('can:products.update')->name('price-lists.prices');
Route::delete('price-lists/{priceList}', [PriceListController::class, 'destroy'])->middleware('can:products.delete')->name('price-lists.destroy');
