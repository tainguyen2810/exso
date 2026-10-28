<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\CostAdjustmentController;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\StockCardController;
use Modules\Inventory\Http\Controllers\StocktakeController;
use Modules\Inventory\Http\Controllers\StockTransferController;

Route::get('inventory', [InventoryController::class, 'index'])->middleware('can:inventory.view')->name('inventory.index');
Route::get('inventory/variants/{variant}', [StockCardController::class, 'show'])->middleware('can:inventory.view')->name('inventory.stock-card');
Route::put('inventory/variants/{variant}/levels', [StockCardController::class, 'levels'])->middleware('can:inventory.update')->name('inventory.levels');

Route::get('stocktakes', [StocktakeController::class, 'index'])->middleware('can:inventory.view')->name('stocktakes.index');
Route::get('stocktakes/create', [StocktakeController::class, 'create'])->middleware('can:inventory.create')->name('stocktakes.create');
Route::post('stocktakes', [StocktakeController::class, 'store'])->middleware('can:inventory.create')->name('stocktakes.store');
Route::get('stocktakes/{stocktake}', [StocktakeController::class, 'show'])->middleware('can:inventory.view')->name('stocktakes.show');
Route::put('stocktakes/{stocktake}', [StocktakeController::class, 'update'])->middleware('can:inventory.update')->name('stocktakes.update');
Route::post('stocktakes/{stocktake}/cancel', [StocktakeController::class, 'cancel'])->middleware('can:inventory.delete')->name('stocktakes.cancel');

Route::get('stock-transfers', [StockTransferController::class, 'index'])->middleware('can:inventory.view')->name('stock-transfers.index');
Route::get('stock-transfers/create', [StockTransferController::class, 'create'])->middleware('can:inventory.create')->name('stock-transfers.create');
Route::post('stock-transfers', [StockTransferController::class, 'store'])->middleware('can:inventory.create')->name('stock-transfers.store');
Route::get('stock-transfers/{transfer}', [StockTransferController::class, 'show'])->middleware('can:inventory.view')->name('stock-transfers.show');
Route::put('stock-transfers/{transfer}', [StockTransferController::class, 'update'])->middleware('can:inventory.update')->name('stock-transfers.update');
Route::post('stock-transfers/{transfer}/ship', [StockTransferController::class, 'ship'])->middleware('can:inventory.approve')->name('stock-transfers.ship');
Route::post('stock-transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->middleware('can:inventory.approve')->name('stock-transfers.receive');
Route::post('stock-transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->middleware('can:inventory.delete')->name('stock-transfers.cancel');

Route::get('cost-adjustments', [CostAdjustmentController::class, 'index'])->middleware('can:inventory.view')->name('cost-adjustments.index');
Route::get('cost-adjustments/create', [CostAdjustmentController::class, 'create'])->middleware('can:inventory.create')->name('cost-adjustments.create');
Route::post('cost-adjustments', [CostAdjustmentController::class, 'store'])->middleware('can:inventory.create')->name('cost-adjustments.store');
Route::get('cost-adjustments/{adjustment}', [CostAdjustmentController::class, 'show'])->middleware('can:inventory.view')->name('cost-adjustments.show');
Route::put('cost-adjustments/{adjustment}', [CostAdjustmentController::class, 'update'])->middleware('can:inventory.update')->name('cost-adjustments.update');
Route::post('cost-adjustments/{adjustment}/cancel', [CostAdjustmentController::class, 'cancel'])->middleware('can:inventory.delete')->name('cost-adjustments.cancel');
