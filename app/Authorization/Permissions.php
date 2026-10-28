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
        'view_all' => 'Xem tất cả',
        'create' => 'Thêm',
        'update' => 'Sửa',
        'delete' => 'Xóa',
        'approve' => 'Duyệt',
        'cancel' => 'Hủy',
        'ship' => 'Đóng gói và giao hàng',
        'pay' => 'Thanh toán',
        'export' => 'Xuất file',
        'import' => 'Nhập file',
    ];

    /**
     * Module => definition, shown on the role page grouped like Sapo.
     * Modules arrive phase by phase; listing them now lets roles be set up
     * once. Only add actions (or modules): renaming one breaks saved roles.
     *
     * "view" opens the module; where a module also has "view_all", "view"
     * only shows the records the user is in charge of (e.g. their own
     * orders) and "view_all" shows everyone's.
     *
     * @return array<string, array{label: string, group: string, actions: list<string>, labels?: array<string, string>}>
     */
    public static function modules(): array
    {
        return [
            'dashboard' => ['label' => 'Tổng quan', 'group' => 'Tổng quan', 'actions' => ['view']],
            'orders' => [
                'label' => 'Đơn hàng', 'group' => 'Đơn hàng',
                'actions' => ['view', 'view_all', 'create', 'update', 'delete', 'approve', 'cancel', 'ship', 'pay', 'export', 'import'],
                'labels' => ['view' => 'Xem đơn được phụ trách', 'view_all' => 'Xem tất cả đơn'],
            ],
            'returns' => [
                'label' => 'Khách trả hàng', 'group' => 'Đơn hàng',
                'actions' => ['view', 'create', 'pay', 'export'],
                'labels' => ['create' => 'Tạo đơn trả hàng', 'pay' => 'Hoàn tiền'],
            ],
            'pos' => ['label' => 'Bán tại quầy', 'group' => 'Đơn hàng', 'actions' => ['view', 'create'], 'labels' => ['create' => 'Bán hàng']],
            'products' => [
                'label' => 'Sản phẩm', 'group' => 'Sản phẩm',
                'actions' => ['view', 'create', 'update', 'delete', 'export', 'import'],
            ],
            'inventory' => [
                'label' => 'Kho hàng', 'group' => 'Sản phẩm',
                'actions' => ['view', 'create', 'update', 'delete', 'approve', 'export', 'import'],
                'labels' => ['approve' => 'Cân bằng kho, xuất/nhận chuyển hàng, áp dụng giá vốn'],
            ],
            'suppliers' => [
                'label' => 'Nhà cung cấp', 'group' => 'Sản phẩm',
                'actions' => ['view', 'create', 'update', 'delete', 'export', 'import'],
            ],
            'purchases' => [
                'label' => 'Đặt hàng nhập, nhập hàng', 'group' => 'Sản phẩm',
                'actions' => ['view', 'create', 'update', 'delete', 'approve', 'cancel', 'pay', 'export'],
                'labels' => ['approve' => 'Nhập kho, trả hàng nhập', 'pay' => 'Thanh toán nhà cung cấp'],
            ],
            'customers' => [
                'label' => 'Khách hàng', 'group' => 'Khách hàng',
                'actions' => ['view', 'view_all', 'create', 'update', 'delete', 'export', 'import'],
                'labels' => ['view' => 'Xem khách được phụ trách', 'view_all' => 'Xem tất cả khách hàng'],
            ],
            'cashbook' => [
                'label' => 'Phiếu thu, phiếu chi', 'group' => 'Sổ quỹ',
                'actions' => ['view', 'create', 'update', 'delete', 'approve', 'cancel', 'export'],
            ],
            'reports' => ['label' => 'Báo cáo', 'group' => 'Báo cáo', 'actions' => ['view', 'export']],
            'promotions' => ['label' => 'Chương trình khuyến mại', 'group' => 'Khuyến mại', 'actions' => ['view', 'create', 'update', 'delete']],
            'coupons' => ['label' => 'Mã giảm giá', 'group' => 'Khuyến mại', 'actions' => ['view', 'create', 'update', 'delete']],
            'loyalty' => [
                'label' => 'Tích điểm, hạng thành viên', 'group' => 'Khuyến mại',
                'actions' => ['view', 'update'],
                'labels' => ['view' => 'Xem điểm khách hàng', 'update' => 'Cấu hình, điều chỉnh điểm'],
            ],
            'settings' => [
                'label' => 'Cấu hình cửa hàng', 'group' => 'Cấu hình',
                'actions' => ['view', 'update'],
            ],
            'staff' => ['label' => 'Nhân viên', 'group' => 'Cấu hình', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
            'roles' => ['label' => 'Vai trò & phân quyền', 'group' => 'Cấu hình', 'actions' => ['view', 'create', 'update', 'delete']],
            'branches' => [
                'label' => 'Chi nhánh', 'group' => 'Cấu hình',
                'actions' => ['view', 'create', 'update', 'delete', 'export', 'import'],
            ],
            'activity_log' => ['label' => 'Nhật ký hoạt động', 'group' => 'Cấu hình', 'actions' => ['view', 'export']],
        ];
    }

    /**
     * Data permissions, set per staff member rather than per role (like
     * Sapo's "Phân quyền dữ liệu"). Named like the others so `can()` works.
     *
     * @return array<string, string>
     */
    public static function dataPermissions(): array
    {
        return [
            'products.view_cost' => 'Xem giá vốn, giá nhập',
            'inventory.view_transfer_price' => 'Xem giá chuyển hàng',
        ];
    }

    /**
     * Label of a permission for screens and errors, e.g. "Đơn hàng: Hủy".
     */
    public static function label(string $name): string
    {
        if (isset(self::dataPermissions()[$name])) {
            return self::dataPermissions()[$name];
        }

        [$module, $action] = array_pad(explode('.', $name, 2), 2, '');
        $definition = self::modules()[$module] ?? null;

        if ($definition === null) {
            return $name;
        }

        return $definition['label'].': '.($definition['labels'][$action] ?? self::ACTIONS[$action] ?? $action);
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::all(), true);
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

        return [...$names, ...array_keys(self::dataPermissions())];
    }

    /**
     * Roles every new store starts with. "Quản trị" gets everything.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        return [
            'Quản trị' => self::rolePermissions(),
            'Thu ngân' => [
                'dashboard.view', 'pos.view', 'pos.create',
                'orders.view', 'orders.view_all', 'orders.create', 'orders.approve', 'orders.ship', 'orders.pay',
                'returns.view', 'returns.create', 'returns.pay',
                'customers.view', 'customers.view_all', 'customers.create', 'products.view',
            ],
            'Nhân viên kho' => [
                'dashboard.view', 'products.view', 'products.create', 'products.update', 'products.export', 'products.import',
                'inventory.view', 'inventory.create', 'inventory.update', 'inventory.approve', 'inventory.export', 'inventory.import',
                'suppliers.view', 'suppliers.create', 'suppliers.update',
                'purchases.view', 'purchases.create', 'purchases.update', 'purchases.approve',
                'orders.view', 'orders.view_all', 'orders.ship',
            ],
            'Kế toán' => [
                'dashboard.view', 'cashbook.view', 'cashbook.create', 'cashbook.update', 'cashbook.approve', 'cashbook.cancel', 'cashbook.export',
                'reports.view', 'reports.export', 'orders.view', 'orders.view_all', 'orders.export', 'returns.view', 'returns.export',
                'customers.view', 'customers.view_all', 'customers.export',
                'suppliers.view', 'suppliers.export', 'purchases.view', 'purchases.pay', 'purchases.export',
            ],
        ];
    }

    /**
     * Permissions that can go in a role (all but the data permissions).
     *
     * @return list<string>
     */
    public static function rolePermissions(): array
    {
        return array_values(array_diff(self::all(), array_keys(self::dataPermissions())));
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
