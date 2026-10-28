<?php

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

Route::get('install', [InstallController::class, 'show'])->name('install.show');
Route::post('install', [InstallController::class, 'store'])->name('install.store');

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
