import { Head, Link, router } from '@inertiajs/react';
import { Package } from 'lucide-react';
import { useState } from 'react';
import { MoneyInput } from '@/components/app/money-input';
import { TopbarActions } from '@/components/app/topbar';
import { DataTable } from '@/components/data-table';
import type { Column, FilterOption, TableData } from '@/components/data-table';
import { Pill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
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
    unit_cost: number | null;
    cost_after: number | null;
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
    cost_price: number | null;
    retail_price: number;
    tracks_inventory: boolean;
};

type Stock = {
    branch_id: number;
    branch: string;
    on_hand: number;
    reserved: number;
    available: number;
    min_quantity: number | null;
    max_quantity: number | null;
};

/** Min / max stock of one branch, edited in a small popover. */
function Levels({
    variantId,
    row,
    editable,
}: {
    variantId: number;
    row: Stock;
    editable: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [min, setMin] = useState<number | null>(row.min_quantity);
    const [max, setMax] = useState<number | null>(row.max_quantity);
    const [error, setError] = useState<string | null>(null);
    const text =
        row.min_quantity === null && row.max_quantity === null
            ? 'Chưa đặt'
            : `${row.min_quantity === null ? '—' : formatNumber(row.min_quantity)} – ${row.max_quantity === null ? '—' : formatNumber(row.max_quantity)}`;

    if (!editable) {
        return <span className="text-muted-foreground">{text}</span>;
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="text-primary hover:underline"
                    title="Đặt tồn tối thiểu / tối đa"
                >
                    {text}
                </button>
            </PopoverTrigger>
            <PopoverContent className="grid w-64 gap-3" align="end">
                <div className="text-sm font-medium">
                    Định mức tồn ở {row.branch}
                </div>
                <label className="grid gap-1 text-sm">
                    Tồn tối thiểu
                    <MoneyInput value={min} onValueChange={setMin} />
                </label>
                <label className="grid gap-1 text-sm">
                    Tồn tối đa
                    <MoneyInput value={max} onValueChange={setMax} />
                </label>
                {error && <p className="text-sm text-destructive">{error}</p>}
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(
                            `/inventory/variants/${variantId}/levels`,
                            {
                                branch_id: row.branch_id,
                                min_quantity: min,
                                max_quantity: max,
                            },
                            {
                                preserveScroll: true,
                                onSuccess: () => setOpen(false),
                                onError: (errors) =>
                                    setError(Object.values(errors)[0] ?? null),
                            },
                        )
                    }
                >
                    Lưu
                </Button>
            </PopoverContent>
        </Popover>
    );
}

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
        cell: (row) => <Pill>{row.type_label}</Pill>,
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
        cell: (row) => formatNumber(row.cost_after ?? 0),
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
            {can('products.view') && (
                <TopbarActions>
                    <Button variant="outline" asChild>
                        <Link href={`/products/${variant.product_id}/edit`}>
                            <Package /> Xem sản phẩm
                        </Link>
                    </Button>
                </TopbarActions>
            )}
            <div className="mx-auto max-w-[1200px] space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div>
                    <h2 className="text-xl font-medium">{variant.name}</h2>
                    <p className="text-sm text-muted-foreground">
                        Mã SKU {variant.sku}
                        {variant.barcode && ` · Mã vạch ${variant.barcode}`}.
                        Mọi thay đổi tồn kho đều ghi ở đây.
                    </p>
                </div>
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
                                            <TableHead className="text-right">
                                                Định mức tồn
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
                                                <TableCell className="text-right tabular-nums">
                                                    <Levels
                                                        variantId={variant.id}
                                                        row={row}
                                                        editable={
                                                            can(
                                                                'inventory.update',
                                                            ) &&
                                                            variant.tracks_inventory
                                                        }
                                                    />
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
                            {variant.cost_price !== null && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Giá vốn bình quân
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {formatNumber(variant.cost_price)}
                                    </span>
                                </div>
                            )}
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
                    columns={
                        variant.cost_price === null
                            ? columns.filter(
                                  (column) => column.key !== 'cost_after',
                              )
                            : columns
                    }
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
                    exportable={can('inventory.export')}
                    empty="Chưa có biến động kho."
                />
            </div>
        </>
    );
}

StockCard.layout = {
    breadcrumbs: [
        { title: 'Quản lý kho', href: '/inventory' },
        { title: 'Thẻ kho', href: '#' },
    ],
};
