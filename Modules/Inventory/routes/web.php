<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\StockCardController;

Route::get('inventory', [InventoryController::class, 'index'])->middleware('can:inventory.view')->name('inventory.index');
Route::get('inventory/variants/{variant}', [StockCardController::class, 'show'])->middleware('can:inventory.view')->name('inventory.stock-card');
