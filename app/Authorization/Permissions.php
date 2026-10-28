<?php

namespace App\Authorization;

use App\Models\Role;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The list of permissions is defined in code: module × action, named
 * "<module>.<action>" (e.g. "orders.approve"). Roles are per store.
 */
class Permissions
{
    public const ACTIONS = [
        'view' => 'Xem',
        'create' => 'Thêm',
        'update' => 'Sửa',
        'delete' => 'Xóa',
        'approve' => 'Duyệt',
        'view_cost' => 'Xem giá vốn, giá nhập',
    ];

    /**
     * Module => [label, actions]. Modules arrive phase by phase; listing them
     * now lets roles be set up once.
     *
     * @return array<string, array{label: string, actions: list<string>}>
     */
    public static function modules(): array
    {
        return [
            'dashboard' => ['label' => 'Tổng quan', 'actions' => ['view']],
            'products' => ['label' => 'Sản phẩm', 'actions' => ['view', 'create', 'update', 'delete', 'view_cost']],
            'inventory' => ['label' => 'Kho hàng', 'actions' => ['view', 'create', 'update', 'delete', 'approve']],
            'customers' => ['label' => 'Khách hàng', 'actions' => ['view', 'create', 'update', 'delete']],
            'orders' => ['label' => 'Đơn hàng', 'actions' => ['view', 'create', 'update', 'delete', 'approve']],
            'pos' => ['label' => 'Bán tại quầy', 'actions' => ['view', 'create']],
            'cashbook' => ['label' => 'Sổ quỹ', 'actions' => ['view', 'create', 'update', 'delete', 'approve']],
            'reports' => ['label' => 'Báo cáo', 'actions' => ['view']],
            'staff' => ['label' => 'Nhân viên', 'actions' => ['view', 'create', 'update', 'delete']],
            'roles' => ['label' => 'Vai trò & phân quyền', 'actions' => ['view', 'create', 'update', 'delete']],
            'branches' => ['label' => 'Chi nhánh', 'actions' => ['view', 'create', 'update', 'delete']],
            'settings' => ['label' => 'Cấu hình', 'actions' => ['view', 'update']],
            'activity_log' => ['label' => 'Nhật ký hoạt động', 'actions' => ['view']],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::modules() as $module => $definition) {
            foreach ($definition['actions'] as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        return $names;
    }

    /**
     * Roles every new store starts with. "Quản trị" gets everything.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        return [
            'Quản trị' => self::all(),
            'Thu ngân' => [
                'dashboard.view', 'pos.view', 'pos.create',
                'orders.view', 'orders.create', 'customers.view', 'customers.create', 'products.view',
            ],
            'Nhân viên kho' => [
                'dashboard.view', 'products.view', 'products.create', 'products.update', 'products.view_cost',
                'inventory.view', 'inventory.create', 'inventory.update', 'orders.view',
            ],
            'Kế toán' => [
                'dashboard.view', 'cashbook.view', 'cashbook.create', 'cashbook.update', 'cashbook.approve',
                'reports.view', 'orders.view', 'customers.view',
            ],
        ];
    }

    /**
     * Create any permission rows that are missing. Safe to run repeatedly.
     */
    public static function sync(): void
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

        foreach (array_diff(self::all(), $existing) as $name) {
            Permission::query()->create(['name' => $name, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function createDefaultRoles(Tenant $tenant): void
    {
        self::sync();

        app(TenantContext::class)->run($tenant, function (): void {
            foreach (self::defaultRoles() as $name => $permissions) {
                Role::findOrCreate($name, 'web')->syncPermissions($permissions);
            }
        });
    }
}
