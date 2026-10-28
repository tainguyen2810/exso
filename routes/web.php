<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\SavedViewController;
use Illuminate\Support\Facades\Route;

Route::get('install', [InstallController::class, 'show'])->name('install.show');
Route::post('install', [InstallController::class, 'store'])->name('install.store');

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::post('branch/switch', BranchSwitchController::class)->name('branch.switch');

    Route::post('saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::delete('saved-views/{savedView}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');

    // Business modules built in later phases. Each page is already guarded by
    // its permission so roles can be set up now.
    $upcoming = [
        'customers' => ['Khách hàng', 'G3'],
        'orders' => ['Đơn hàng', 'G3'],
        'pos' => ['Bán tại quầy', 'G3'],
        'cashbook' => ['Sổ quỹ', 'G4'],
        'reports' => ['Báo cáo', 'G4'],
    ];

    foreach ($upcoming as $module => [$title, $phase]) {
        Route::inertia($module, 'coming-soon', ['title' => $title, 'phase' => $phase])
            ->middleware("can:{$module}.view")
            ->name("{$module}.index");
    }

    Route::get('staff', [StaffController::class, 'index'])->middleware('can:staff.view')->name('staff.index');
    Route::post('staff/bulk', [StaffController::class, 'bulk'])->middleware('can:staff.update')->name('staff.bulk');
    Route::get('staff/create', [StaffController::class, 'create'])->middleware('can:staff.create')->name('staff.create');
    Route::post('staff', [StaffController::class, 'store'])->middleware('can:staff.create')->name('staff.store');
    Route::get('staff/{staff}/edit', [StaffController::class, 'edit'])->middleware('can:staff.update')->name('staff.edit');
    Route::put('staff/{staff}', [StaffController::class, 'update'])->middleware('can:staff.update')->name('staff.update');
    Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->middleware('can:staff.delete')->name('staff.destroy');

    Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->middleware('can:roles.create')->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.create')->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->middleware('can:roles.update')->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.update')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('roles.destroy');

    Route::get('branches', [BranchController::class, 'index'])->middleware('can:branches.view')->name('branches.index');
    Route::post('branches/bulk', [BranchController::class, 'bulk'])->middleware('can:branches.update')->name('branches.bulk');
    Route::get('branches/import/template', [BranchController::class, 'template'])->middleware('can:branches.create')->name('branches.import.template');
    Route::post('branches/import', [BranchController::class, 'import'])->middleware('can:branches.create')->name('branches.import');
    Route::get('branches/create', [BranchController::class, 'create'])->middleware('can:branches.create')->name('branches.create');
    Route::post('branches', [BranchController::class, 'store'])->middleware('can:branches.create')->name('branches.store');
    Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])->middleware('can:branches.update')->name('branches.edit');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('can:branches.update')->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('can:branches.delete')->name('branches.destroy');

    Route::get('activity-log', [ActivityLogController::class, 'index'])->middleware('can:activity_log.view')->name('activity-log.index');
});

require __DIR__.'/settings.php';
