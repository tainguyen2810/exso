import {
    BarChart3,
    Building2,
    FolderTree,
    History,
    LayoutGrid,
    Package,
    Receipt,
    Settings,
    ShieldCheck,
    ShoppingBag,
    ShoppingCart,
    Store,
    Tag,
    Tags,
    Truck,
    UserRound,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { NavGroup, NavItem } from '@/types';

/**
 * Main menu, laid out like Sapo GO. A group with children is shown when the
 * user can see at least one child; items without the needed permission are
 * hidden (the server blocks the pages too). Pages of later phases point to
 * "coming soon" routes in routes/web.php.
 */
export const menu: NavGroup[] = [
    {
        items: [
            { title: 'Tổng quan', href: '/dashboard', icon: LayoutGrid },
            {
                title: 'Đơn hàng',
                href: '/orders',
                icon: ShoppingCart,
                children: [
                    {
                        title: 'Tạo đơn và giao hàng',
                        href: '/orders/create',
                        permission: 'orders.create',
                    },
                    {
                        title: 'Danh sách đơn hàng',
                        href: '/orders',
                        permission: 'orders.view',
                    },
                    {
                        title: 'Hóa đơn điện tử',
                        href: '/e-invoices',
                        permission: 'orders.view',
                    },
                    {
                        title: 'Khách trả hàng',
                        href: '/returns',
                        permission: 'orders.view',
                    },
                ],
            },
            {
                title: 'Vận chuyển',
                href: '/shipping',
                icon: Truck,
                children: [
                    {
                        title: 'Tổng quan',
                        href: '/shipping',
                        permission: 'orders.view',
                    },
                    {
                        title: 'Quản lý vận đơn',
                        href: '/shipments',
                        permission: 'orders.view',
                    },
                    {
                        title: 'Đối soát COD và phí',
                        href: '/cod-reconciliations',
                        permission: 'orders.view',
                    },
                    {
                        title: 'Kết nối đối tác',
                        href: '/shipping/partners',
                        permission: 'orders.view',
                    },
                ],
            },
            {
                title: 'Sản phẩm',
                href: '/products',
                icon: Package,
                children: [
                    {
                        title: 'Danh sách sản phẩm',
                        href: '/products',
                        permission: 'products.view',
                    },
                    {
                        title: 'Quản lý kho',
                        href: '/inventory',
                        permission: 'inventory.view',
                    },
                    {
                        title: 'Đặt hàng nhập',
                        href: '/purchase-orders',
                        permission: 'inventory.view',
                    },
                    {
                        title: 'Nhập hàng',
                        href: '/purchase-receipts',
                        permission: 'inventory.view',
                    },
                    {
                        title: 'Kiểm hàng',
                        href: '/stocktakes',
                        permission: 'inventory.view',
                    },
                    {
                        title: 'Chuyển hàng',
                        href: '/stock-transfers',
                        permission: 'inventory.view',
                    },
                    {
                        title: 'Nhà cung cấp',
                        href: '/suppliers',
                        permission: 'inventory.view',
                    },
                    {
                        title: 'Điều chỉnh giá vốn',
                        href: '/cost-adjustments',
                        permission: 'inventory.view',
                    },
                ],
            },
            {
                title: 'Khách hàng',
                href: '/customers',
                icon: UserRound,
                children: [
                    {
                        title: 'Danh sách khách hàng',
                        href: '/customers',
                        permission: 'customers.view',
                    },
                    {
                        title: 'Nhóm khách hàng',
                        href: '/customer-groups',
                        permission: 'customers.view',
                    },
                ],
            },
            {
                title: 'Sổ quỹ',
                href: '/cashbook',
                icon: Wallet,
                children: [
                    {
                        title: 'Phiếu thu',
                        href: '/cashbook/receipts',
                        permission: 'cashbook.view',
                    },
                    {
                        title: 'Phiếu chi',
                        href: '/cashbook/payments',
                        permission: 'cashbook.view',
                    },
                    {
                        title: 'Sổ quỹ',
                        href: '/cashbook',
                        permission: 'cashbook.view',
                    },
                ],
            },
            {
                title: 'Báo cáo',
                href: '/reports',
                icon: BarChart3,
                children: [
                    {
                        title: 'Tổng quan báo cáo',
                        href: '/reports',
                        permission: 'reports.view',
                    },
                    {
                        title: 'Báo cáo bán hàng',
                        href: '/reports/sales',
                        permission: 'reports.view',
                    },
                    {
                        title: 'Báo cáo nhập hàng',
                        href: '/reports/purchases',
                        permission: 'reports.view',
                    },
                    {
                        title: 'Báo cáo kho',
                        href: '/reports/inventory',
                        permission: 'reports.view',
                    },
                    {
                        title: 'Báo cáo tài chính',
                        href: '/reports/finance',
                        permission: 'reports.view',
                    },
                    {
                        title: 'Báo cáo khách hàng',
                        href: '/reports/customers',
                        permission: 'reports.view',
                    },
                ],
            },
        ],
    },
    {
        title: 'Kênh bán hàng',
        items: [
            {
                title: 'Bán tại quầy',
                href: '/pos',
                icon: Store,
                permission: 'pos.view',
            },
            {
                title: 'Sàn TMĐT',
                href: '/marketplaces',
                icon: ShoppingBag,
                permission: 'orders.view',
            },
        ],
    },
];

/** Pinned to the bottom of the sidebar, like Sapo. */
export const configItem: NavItem = {
    title: 'Cấu hình',
    href: '/config',
    icon: Settings,
    permission: [
        'settings.view',
        'branches.view',
        'staff.view',
        'roles.view',
        'activity_log.view',
    ],
};

export type ConfigEntry = {
    title: string;
    description: string;
    href: string;
    icon: LucideIcon;
    permission: string;
    /** Phase that builds it; the card is shown greyed out until then. */
    phase?: string;
};

/** Cards on the Cấu hình page. */
export const configEntries: ConfigEntry[] = [
    {
        title: 'Thông tin cửa hàng',
        description: 'Tên, liên hệ, địa chỉ và logo của cửa hàng.',
        href: '/config/store',
        icon: Store,
        permission: 'settings.view',
        phase: 'tiếp theo',
    },
    {
        title: 'Quản lý chi nhánh',
        description: 'Thêm và quản lý các chi nhánh, kho của cửa hàng.',
        href: '/branches',
        icon: Building2,
        permission: 'branches.view',
    },
    {
        title: 'Nhân viên',
        description: 'Tài khoản nhân viên, chi nhánh làm việc và trạng thái.',
        href: '/staff',
        icon: Users,
        permission: 'staff.view',
    },
    {
        title: 'Vai trò và phân quyền',
        description: 'Tạo vai trò và chọn quyền cho từng nhóm nhân viên.',
        href: '/roles',
        icon: ShieldCheck,
        permission: 'roles.view',
    },
    {
        title: 'Chính sách giá',
        description: 'Giá bán lẻ, bán buôn, giá nhập và các bảng giá riêng.',
        href: '/price-lists',
        icon: Tags,
        permission: 'products.view',
    },
    {
        title: 'Loại sản phẩm',
        description: 'Nhóm sản phẩm để lọc, báo cáo và áp khuyến mại.',
        href: '/product-lists/categories',
        icon: FolderTree,
        permission: 'products.view',
    },
    {
        title: 'Nhãn hiệu',
        description: 'Thương hiệu của sản phẩm.',
        href: '/product-lists/brands',
        icon: Tag,
        permission: 'products.view',
    },
    {
        title: 'Thuế',
        description: 'Thuế suất mặc định khi bán hàng và nhập hàng.',
        href: '/config/taxes',
        icon: Receipt,
        permission: 'settings.view',
        phase: 'tiếp theo',
    },
    {
        title: 'Hình thức thanh toán',
        description: 'Tiền mặt, chuyển khoản, ví điện tử, COD.',
        href: '/config/payment-methods',
        icon: Wallet,
        permission: 'settings.view',
        phase: 'tiếp theo',
    },
    {
        title: 'Nguồn bán hàng',
        description: 'Nơi khách đặt hàng: tại quầy, Facebook, Zalo, sàn TMĐT.',
        href: '/config/sources',
        icon: ShoppingBag,
        permission: 'settings.view',
        phase: 'tiếp theo',
    },
    {
        title: 'Cấu hình bán hàng',
        description: 'Quy tắc khi tạo đơn, tồn kho âm, in hóa đơn.',
        href: '/config/sales',
        icon: ShoppingCart,
        permission: 'settings.view',
        phase: 'tiếp theo',
    },
    {
        title: 'Nhật ký hoạt động',
        description: 'Ai đã làm gì, lúc nào, thay đổi những gì.',
        href: '/activity-log',
        icon: History,
        permission: 'activity_log.view',
    },
];

export function filterMenu(
    groups: NavGroup[],
    can: (permission?: string | string[]) => boolean,
): NavGroup[] {
    const filterItems = (items: NavItem[]): NavItem[] =>
        items
            .filter((item) => can(item.permission))
            .map((item) =>
                item.children
                    ? { ...item, children: filterItems(item.children) }
                    : item,
            )
            .filter((item) => !item.children || item.children.length > 0);

    return groups
        .map((group) => ({ ...group, items: filterItems(group.items) }))
        .filter((group) => group.items.length > 0);
}
