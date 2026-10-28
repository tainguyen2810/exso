<?php

use Illuminate\Support\Facades\Route;
use Modules\Report\Http\Controllers\ReportController;

Route::middleware('can:reports.view')->group(function (): void {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports/saved', [ReportController::class, 'save'])->name('reports.saved.store');
    Route::delete('reports/saved/{saved}', [ReportController::class, 'destroySaved'])->name('reports.saved.destroy');
    Route::get('reports/{group}', [ReportController::class, 'group'])->where('group', '[a-z-]+')->name('reports.group');
    Route::get('reports/{group}/{name}', [ReportController::class, 'show'])->where(['group' => '[a-z-]+', 'name' => '[a-z-]+'])->name('reports.show');
});
