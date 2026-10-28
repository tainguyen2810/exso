<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\Config\FileLogController;
use App\Http\Controllers\Config\PaymentMethodController;
use App\Http\Controllers\Config\ReasonController;
use App\Http\Controllers\Config\SalesSettingsController;
use App\Http\Controllers\Config\SalesSourceController;
use App\Http\Controllers\Config\StoreInfoController;
use App\Http\Controllers\Config\TaxController;
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

    // Store settings pages (Cấu hình). Reading needs settings.view, saving settings.update.
    Route::prefix('config')->name('config.')->group(function () {
        Route::get('store', [StoreInfoController::class, 'show'])->middleware('can:settings.view')->name('store');
        Route::post('store', [StoreInfoController::class, 'update'])->middleware('can:settings.update')->name('store.update');

        Route::get('taxes', [TaxController::class, 'index'])->middleware('can:settings.view')->name('taxes.index');
        Route::put('taxes/defaults', [TaxController::class, 'updateDefaults'])->middleware('can:settings.update')->name('taxes.defaults');
        Route::post('taxes', [TaxController::class, 'store'])->middleware('can:settings.update')->name('taxes.store');
        Route::put('taxes/{taxRate}', [TaxController::class, 'update'])->middleware('can:settings.update')->name('taxes.update');
        Route::delete('taxes/{taxRate}', [TaxController::class, 'destroy'])->middleware('can:settings.update')->name('taxes.destroy');

        Route::get('payment-methods', [PaymentMethodController::class, 'index'])->middleware('can:settings.view')->name('payment-methods.index');
        Route::put('payment-methods/defaults', [PaymentMethodController::class, 'updateDefaults'])->middleware('can:settings.update')->name('payment-methods.defaults');
        Route::post('payment-methods', [PaymentMethodController::class, 'store'])->middleware('can:settings.update')->name('payment-methods.store');
        Route::put('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->middleware('can:settings.update')->name('payment-methods.update');
        Route::delete('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->middleware('can:settings.update')->name('payment-methods.destroy');

        Route::get('sources', [SalesSourceController::class, 'index'])->middleware('can:settings.view')->name('sources.index');
        Route::post('sources', [SalesSourceController::class, 'store'])->middleware('can:settings.update')->name('sources.store');
        Route::put('sources/{salesSource}', [SalesSourceController::class, 'update'])->middleware('can:settings.update')->name('sources.update');
        Route::delete('sources/{salesSource}', [SalesSourceController::class, 'destroy'])->middleware('can:settings.update')->name('sources.destroy');

        Route::get('reasons', [ReasonController::class, 'index'])->middleware('can:settings.view')->name('reasons.index');
        Route::put('reasons/settings', [ReasonController::class, 'updateSettings'])->middleware('can:settings.update')->name('reasons.settings');
        Route::post('reasons', [ReasonController::class, 'store'])->middleware('can:settings.update')->name('reasons.store');
        Route::put('reasons/{reason}', [ReasonController::class, 'update'])->middleware('can:settings.update')->name('reasons.update');
        Route::delete('reasons/{reason}', [ReasonController::class, 'destroy'])->middleware('can:settings.update')->name('reasons.destroy');

        Route::get('sales', [SalesSettingsController::class, 'show'])->middleware('can:settings.view')->name('sales');
        Route::put('sales', [SalesSettingsController::class, 'update'])->middleware('can:settings.update')->name('sales.update');

        Route::get('file-logs', [FileLogController::class, 'index'])->middleware('can:activity_log.view')->name('file-logs.index');
    });

    // Shown in the sidebar to every signed-in user of the store.
    Route::get('store-logo', [StoreInfoController::class, 'logo'])->name('store-logo');

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
