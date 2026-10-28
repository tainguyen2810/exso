<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\SavedViewController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('install', [InstallController::class, 'show'])->name('install.show');
Route::post('install', [InstallController::class, 'store'])->name('install.store');

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::post('branch/switch', BranchSwitchController::class)->name('branch.switch');

    Route::post('saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::delete('saved-views/{savedView}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');

    // Pages of later phases, shown as "coming soon" so the menu matches the
    // final layout. Each is guarded by its permission so roles can be set up
    // now. A phase that builds a page removes its line here.
    $upcoming = [
        'orders/create' => ['Tạo đơn và giao hàng', 'G3', 'orders.create'],
        'orders' => ['Danh sách đơn hàng', 'G3', 'orders.view'],
        'returns' => ['Khách trả hàng', 'G3', 'orders.view'],
        'e-invoices' => ['Hóa đơn điện tử', 'G7', 'orders.view'],
        'shipping' => ['Tổng quan vận chuyển', 'G6', 'orders.view'],
        'shipments' => ['Quản lý vận đơn', 'G6', 'orders.view'],
        'cod-reconciliations' => ['Đối soát COD và phí', 'G6', 'orders.view'],
        'shipping/partners' => ['Kết nối đối tác vận chuyển', 'G6', 'orders.view'],
        'purchase-orders' => ['Đặt hàng nhập', 'G2', 'inventory.view'],
        'purchase-receipts' => ['Nhập hàng', 'G2', 'inventory.view'],
        'stocktakes' => ['Kiểm hàng', 'G2', 'inventory.view'],
        'stock-transfers' => ['Chuyển hàng', 'G2', 'inventory.view'],
        'suppliers' => ['Nhà cung cấp', 'G2', 'inventory.view'],
        'cost-adjustments' => ['Điều chỉnh giá vốn', 'G2', 'inventory.view'],
        'customers' => ['Danh sách khách hàng', 'G3', 'customers.view'],
        'customer-groups' => ['Nhóm khách hàng', 'G3', 'customers.view'],
        'cashbook/receipts' => ['Phiếu thu', 'G4', 'cashbook.view'],
        'cashbook/payments' => ['Phiếu chi', 'G4', 'cashbook.view'],
        'cashbook' => ['Sổ quỹ', 'G4', 'cashbook.view'],
        'reports' => ['Tổng quan báo cáo', 'G4', 'reports.view'],
        'reports/sales' => ['Báo cáo bán hàng', 'G4', 'reports.view'],
        'reports/purchases' => ['Báo cáo nhập hàng', 'G4', 'reports.view'],
        'reports/inventory' => ['Báo cáo kho', 'G4', 'reports.view'],
        'reports/finance' => ['Báo cáo tài chính', 'G4', 'reports.view'],
        'reports/customers' => ['Báo cáo khách hàng', 'G4', 'reports.view'],
        'pos' => ['Bán tại quầy', 'G3', 'pos.view'],
        'marketplaces' => ['Sàn TMĐT', 'G7', 'orders.view'],
    ];

    foreach ($upcoming as $path => [$title, $phase, $permission]) {
        Route::inertia($path, 'coming-soon', ['title' => $title, 'phase' => $phase])
            ->middleware("can:{$permission}")
            ->name(str_replace('/', '.', $path).(str_contains($path, '/') ? '' : '.index'));
    }

    // Hub of store settings; open to anyone who can see one of its pages.
    Route::get('config', function (Request $request) {
        abort_unless($request->user()?->canAny(['settings.view', 'branches.view', 'staff.view', 'roles.view', 'activity_log.view', 'products.update']), 403);

        return Inertia::render('config/index');
    })->name('config.index');

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
    Route::get('branches/import/template', [BranchController::class, 'template'])->middleware('can:branches.import')->name('branches.import.template');
    Route::post('branches/import', [BranchController::class, 'import'])->middleware('can:branches.import')->name('branches.import');
    Route::get('branches/create', [BranchController::class, 'create'])->middleware('can:branches.create')->name('branches.create');
    Route::post('branches', [BranchController::class, 'store'])->middleware('can:branches.create')->name('branches.store');
    Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])->middleware('can:branches.update')->name('branches.edit');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('can:branches.update')->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('can:branches.delete')->name('branches.destroy');

    Route::get('activity-log', [ActivityLogController::class, 'index'])->middleware('can:activity_log.view')->name('activity-log.index');
});

require __DIR__.'/settings.php';
