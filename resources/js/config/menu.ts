import {
    Banknote,
    BarChart3,
    Building2,
    History,
    LayoutGrid,
    Package,
    ShieldCheck,
    ShoppingCart,
    Store,
    Users,
    UserRound,
    Warehouse,
} from 'lucide-react';
import type { NavGroup, NavItem } from '@/types';

/**
 * Main menu. Items without the needed permission are hidden; the server
 * blocks the pages too.
 */
export const menu: NavGroup[] = [
    {
        items: [
            { title: 'Tổng quan', href: '/dashboard', icon: LayoutGrid },
            {
                title: 'Đơn hàng',
                href: '/orders',
                icon: ShoppingCart,
                permission: 'orders.view',
            },
            {
                title: 'Bán tại quầy',
                href: '/pos',
                icon: Store,
                permission: 'pos.view',
            },
            {
                title: 'Sản phẩm',
                href: '/products',
                icon: Package,
                permission: 'products.view',
                children: [
                    {
                        title: 'Danh sách sản phẩm',
                        href: '/products',
                        permission: 'products.view',
                    },
                    {
                        title: 'Loại sản phẩm',
                        href: '/product-lists/categories',
                        permission: 'products.view',
                    },
                    {
                        title: 'Nhãn hiệu',
                        href: '/product-lists/brands',
                        permission: 'products.view',
                    },
                    {
                        title: 'Chính sách giá',
                        href: '/price-lists',
                        permission: 'products.view',
                    },
                    {
                        title: 'In mã vạch',
                        href: '/products/barcodes',
                        permission: 'products.view',
                    },
                ],
            },
            {
                title: 'Kho hàng',
                href: '/inventory',
                icon: Warehouse,
                permission: 'inventory.view',
                children: [
                    {
                        title: 'Tồn kho',
                        href: '/inventory',
                        permission: 'inventory.view',
                    },
                ],
            },
            {
                title: 'Khách hàng',
                href: '/customers',
                icon: UserRound,
                permission: 'customers.view',
            },
            {
                title: 'Sổ quỹ',
                href: '/cashbook',
                icon: Banknote,
                permission: 'cashbook.view',
            },
            {
                title: 'Báo cáo',
                href: '/reports',
                icon: BarChart3,
                permission: 'reports.view',
            },
        ],
    },
    {
        title: 'Quản trị',
        items: [
            {
                title: 'Nhân viên',
                href: '/staff',
                icon: Users,
                permission: 'staff.view',
            },
            {
                title: 'Vai trò & phân quyền',
                href: '/roles',
                icon: ShieldCheck,
                permission: 'roles.view',
            },
            {
                title: 'Chi nhánh',
                href: '/branches',
                icon: Building2,
                permission: 'branches.view',
            },
            {
                title: 'Nhật ký hoạt động',
                href: '/activity-log',
                icon: History,
                permission: 'activity_log.view',
            },
        ],
    },
];

export function filterMenu(
    groups: NavGroup[],
    can: (permission?: string) => boolean,
): NavGroup[] {
    const filterItems = (items: NavItem[]): NavItem[] =>
        items
            .filter((item) => can(item.permission))
            .map((item) =>
                item.children
                    ? { ...item, children: filterItems(item.children) }
                    : item,
            );

    return groups
        .map((group) => ({ ...group, items: filterItems(group.items) }))
        .filter((group) => group.items.length > 0);
}
