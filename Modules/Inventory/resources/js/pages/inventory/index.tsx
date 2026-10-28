import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/app/page-header';
import { DataTable } from '@/components/data-table';
import type { Column, FilterOption, TableData } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
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
    cost_price: number;
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
                        <Badge
                            variant="outline"
                            className="ml-2 text-amber-600"
                        >
                            Dưới định mức
                        </Badge>
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
        cell: (row) => formatNumber(row.cost_price),
    },
    {
        key: 'value',
        header: 'Giá trị tồn',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(Math.max(row.on_hand, 0) * row.cost_price),
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
    totals: { on_hand: number; value: number };
    branches: Option[];
    categories: Option[];
    brands: Option[];
}) {
    const toOptions = (items: Option[]): FilterOption[] =>
        items.map((item) => ({ value: String(item.id), label: item.name }));

    return (
        <>
            <Head title="Tồn kho" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Tồn kho"
                    description="Tồn kho = số đang có trong kho. Có thể bán = tồn kho trừ hàng đang giữ cho đơn chưa xuất."
                />
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
                    <Card>
                        <CardContent className="space-y-1">
                            <div className="text-sm text-muted-foreground">
                                Giá trị tồn (theo giá vốn)
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {formatNumber(totals.value)} đ
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <DataTable
                    tableKey="inventory"
                    data={stock}
                    columns={columns}
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
                    exportable
                    empty="Chưa có hàng trong kho."
                />
            </div>
        </>
    );
}

InventoryIndex.layout = {
    breadcrumbs: [
        { title: 'Kho hàng', href: '/inventory' },
        { title: 'Tồn kho', href: '/inventory' },
    ],
};
