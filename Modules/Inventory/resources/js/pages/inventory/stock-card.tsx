import { Head, Link } from '@inertiajs/react';
import { Package } from 'lucide-react';
import { PageHeader } from '@/components/app/page-header';
import { DataTable } from '@/components/data-table';
import type { Column, FilterOption, TableData } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Movement = {
    id: number;
    created_at: string | null;
    branch: string;
    type: string;
    type_label: string;
    reference_code: string | null;
    quantity: number;
    on_hand_after: number;
    unit_cost: number;
    cost_after: number;
    creator: string | null;
    note: string | null;
};

type Variant = {
    id: number;
    product_id: number;
    name: string;
    sku: string;
    barcode: string | null;
    unit: string | null;
    cost_price: number;
    retail_price: number;
    tracks_inventory: boolean;
};

type Stock = {
    branch_id: number;
    branch: string;
    on_hand: number;
    reserved: number;
    available: number;
};

const columns: Column<Movement>[] = [
    {
        key: 'created_at',
        header: 'Thời gian',
        sort: 'created_at',
        fixed: true,
        className: 'whitespace-nowrap',
        cell: (row) => formatDateTime(row.created_at),
    },
    { key: 'branch', header: 'Chi nhánh', cell: (row) => row.branch },
    {
        key: 'type',
        header: 'Nghiệp vụ',
        cell: (row) => <Badge variant="outline">{row.type_label}</Badge>,
    },
    {
        key: 'reference_code',
        header: 'Chứng từ',
        cell: (row) => row.reference_code,
    },
    {
        key: 'quantity',
        header: 'Thay đổi',
        className: 'text-right tabular-nums',
        cell: (row) => (
            <span
                className={cn(
                    'font-medium',
                    row.quantity > 0 ? 'text-emerald-600' : 'text-destructive',
                )}
            >
                {row.quantity > 0 ? '+' : ''}
                {formatNumber(row.quantity)}
            </span>
        ),
    },
    {
        key: 'on_hand_after',
        header: 'Tồn sau',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.on_hand_after),
    },
    {
        key: 'cost_after',
        header: 'Giá vốn',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.cost_after),
    },
    { key: 'creator', header: 'Người thực hiện', cell: (row) => row.creator },
    {
        key: 'note',
        header: 'Ghi chú',
        hidden: true,
        className: 'max-w-64 truncate',
        cell: (row) => row.note,
    },
];

export default function StockCard({
    variant,
    stock,
    movements,
    branches,
    types,
}: {
    variant: Variant;
    stock: Stock[];
    movements: TableData<Movement>;
    branches: { id: number; name: string }[];
    types: FilterOption[];
}) {
    const can = useCan();

    return (
        <>
            <Head title={`Thẻ kho ${variant.sku}`} />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title={`Thẻ kho: ${variant.name}`}
                    description={`Mã SKU ${variant.sku}${variant.barcode ? ` · Mã vạch ${variant.barcode}` : ''}. Mọi thay đổi tồn kho đều ghi ở đây.`}
                    actions={
                        can('products.view') && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/products/${variant.product_id}/edit`}
                                >
                                    <Package /> Xem sản phẩm
                                </Link>
                            </Button>
                        )
                    }
                />
                <div className="grid items-start gap-4 lg:grid-cols-12">
                    <Card className="lg:col-span-8">
                        <CardHeader>
                            <CardTitle>Tồn kho theo chi nhánh</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {stock.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {variant.tracks_inventory
                                        ? 'Chưa có tồn kho.'
                                        : 'Phiên bản này không quản lý tồn kho.'}
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Chi nhánh</TableHead>
                                            <TableHead className="text-right">
                                                Tồn kho
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Đang giữ
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Có thể bán
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {stock.map((row) => (
                                            <TableRow key={row.branch_id}>
                                                <TableCell>
                                                    {row.branch}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatNumber(row.on_hand)}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatNumber(row.reserved)}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatNumber(
                                                        row.available,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                    <Card className="lg:col-span-4">
                        <CardHeader>
                            <CardTitle>Giá</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Giá vốn bình quân
                                </span>
                                <span className="font-medium tabular-nums">
                                    {formatNumber(variant.cost_price)}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Giá bán lẻ
                                </span>
                                <span className="font-medium tabular-nums">
                                    {formatNumber(variant.retail_price)}
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <DataTable
                    tableKey="stock-card"
                    data={movements}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã chứng từ, ghi chú"
                    filters={[
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: branches.map((branch) => ({
                                value: String(branch.id),
                                label: branch.name,
                            })),
                        },
                        {
                            key: 'type',
                            label: 'Nghiệp vụ',
                            type: 'select',
                            options: types,
                        },
                        {
                            key: 'created_at',
                            label: 'Thời gian',
                            type: 'date-range',
                        },
                    ]}
                    exportable
                    empty="Chưa có biến động kho."
                />
            </div>
        </>
    );
}

StockCard.layout = {
    breadcrumbs: [
        { title: 'Kho hàng', href: '/inventory' },
        { title: 'Thẻ kho', href: '#' },
    ],
};
