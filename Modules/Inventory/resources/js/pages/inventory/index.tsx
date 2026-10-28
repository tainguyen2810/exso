import { Head, Link } from '@inertiajs/react';
import { Pill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, FilterOption, TableData } from '@/components/data-table';
import { Card, CardContent } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    product_id: number;
    name: string;
    sku: string;
    barcode: string | null;
    unit: string | null;
    image_url: string | null;
    category: string | null;
    on_hand: number;
    reserved: number;
    available: number;
    min_quantity: number | null;
    cost_price: number | null;
};

type Option = { id: number; name: string };

const quantity = (value: number) => (
    <span className={cn(value < 0 && 'font-medium text-destructive')}>
        {formatNumber(value)}
    </span>
);

const columns: Column<Row>[] = [
    {
        key: 'name',
        header: 'Phiên bản',
        sort: 'name',
        fixed: true,
        cell: (row) => (
            <Link
                href={`/inventory/variants/${row.id}`}
                className="block min-w-0"
            >
                <span className="block font-medium text-primary hover:underline">
                    {row.name}
                </span>
                <span className="text-xs text-muted-foreground">
                    {row.sku}
                    {row.unit ? ` · ${row.unit}` : ''}
                </span>
            </Link>
        ),
    },
    {
        key: 'category',
        header: 'Loại sản phẩm',
        cell: (row) => row.category,
    },
    {
        key: 'on_hand',
        header: 'Tồn kho',
        sort: 'on_hand',
        className: 'text-right tabular-nums',
        cell: (row) => (
            <>
                {quantity(row.on_hand)}
                {row.min_quantity !== null &&
                    row.on_hand < row.min_quantity && (
                        <Pill tone="warning" className="ml-2">
                            Dưới định mức
                        </Pill>
                    )}
            </>
        ),
    },
    {
        key: 'reserved',
        header: 'Đang giữ',
        sort: 'reserved',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.reserved),
    },
    {
        key: 'available',
        header: 'Có thể bán',
        className: 'text-right tabular-nums',
        cell: (row) => quantity(row.available),
    },
    {
        key: 'cost_price',
        header: 'Giá vốn',
        sort: 'cost_price',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.cost_price ?? 0),
    },
    {
        key: 'value',
        header: 'Giá trị tồn',
        className: 'text-right tabular-nums',
        cell: (row) =>
            formatNumber(Math.max(row.on_hand, 0) * (row.cost_price ?? 0)),
    },
];

const stockOptions: FilterOption[] = [
    { value: 'in_stock', label: 'Còn hàng' },
    { value: 'out_of_stock', label: 'Hết hàng' },
    { value: 'negative', label: 'Tồn âm' },
    { value: 'below_min', label: 'Dưới định mức' },
];

export default function InventoryIndex({
    stock,
    totals,
    branches,
    categories,
    brands,
}: {
    stock: TableData<Row>;
    totals: { on_hand: number; value: number | null };
    branches: Option[];
    categories: Option[];
    brands: Option[];
}) {
    const can = useCan();
    const toOptions = (items: Option[]): FilterOption[] =>
        items.map((item) => ({ value: String(item.id), label: item.name }));
    // The server sends no cost without the cost permission.
    const showCost = totals.value !== null;

    return (
        <>
            <Head title="Tồn kho" />
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="space-y-1">
                            <div className="text-sm text-muted-foreground">
                                Tổng tồn kho
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {formatNumber(totals.on_hand)}
                            </div>
                        </CardContent>
                    </Card>
                    {showCost && (
                        <Card>
                            <CardContent className="space-y-1">
                                <div className="text-sm text-muted-foreground">
                                    Giá trị tồn (theo giá vốn)
                                </div>
                                <div className="text-2xl font-semibold tabular-nums">
                                    {formatNumber(totals.value ?? 0)} đ
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
                <DataTable
                    tableKey="inventory"
                    data={stock}
                    columns={
                        showCost
                            ? columns
                            : columns.filter(
                                  (column) =>
                                      !['cost_price', 'value'].includes(
                                          column.key,
                                      ),
                              )
                    }
                    searchPlaceholder="Tìm theo tên, mã SKU, mã vạch"
                    tabs={
                        branches.length > 1
                            ? {
                                  filter: 'branch_id',
                                  options: toOptions(branches),
                              }
                            : undefined
                    }
                    filters={[
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toOptions(branches),
                        },
                        {
                            key: 'stock',
                            label: 'Tình trạng tồn',
                            type: 'select',
                            options: stockOptions,
                        },
                        {
                            key: 'category_id',
                            label: 'Loại sản phẩm',
                            type: 'select',
                            options: toOptions(categories),
                        },
                        {
                            key: 'brand_id',
                            label: 'Nhãn hiệu',
                            type: 'select',
                            options: toOptions(brands),
                        },
                    ]}
                    exportable={can('inventory.export')}
                    empty="Chưa có hàng trong kho."
                />
            </div>
        </>
    );
}

InventoryIndex.layout = {
    breadcrumbs: [{ title: 'Quản lý kho', href: '/inventory' }],
};
