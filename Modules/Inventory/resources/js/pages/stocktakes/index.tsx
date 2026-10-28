import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { StatusPill, statusOptions } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { stocktakeStatuses } from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    branch: string;
    status: string;
    items_count: number;
    note: string | null;
    creator: string | null;
    balancer: string | null;
    created_at: string | null;
    balanced_at: string | null;
};

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã phiếu',
        sort: 'code',
        fixed: true,
        className: 'whitespace-nowrap',
        cell: (row) => (
            <RecordLink href={`/stocktakes/${row.id}`}>{row.code}</RecordLink>
        ),
    },
    { key: 'branch', header: 'Chi nhánh', cell: (row) => row.branch },
    {
        key: 'status',
        header: 'Trạng thái',
        cell: (row) => (
            <StatusPill statuses={stocktakeStatuses} value={row.status} />
        ),
    },
    {
        key: 'items_count',
        header: 'Số sản phẩm',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.items_count),
    },
    {
        key: 'created_at',
        header: 'Ngày tạo',
        sort: 'created_at',
        cell: (row) => formatDateTime(row.created_at),
    },
    {
        key: 'balanced_at',
        header: 'Ngày cân bằng',
        sort: 'balanced_at',
        cell: (row) => formatDateTime(row.balanced_at),
    },
    { key: 'creator', header: 'Người tạo', cell: (row) => row.creator },
    {
        key: 'note',
        header: 'Ghi chú',
        hidden: true,
        cell: (row) => row.note,
    },
];

export default function StocktakesIndex({
    stocktakes,
    branches,
}: {
    stocktakes: TableData<Row>;
    branches: { id: number; name: string }[];
}) {
    const can = useCan();

    return (
        <>
            <Head title="Kiểm hàng" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="stocktakes"
                    data={stocktakes}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã phiếu, ghi chú"
                    tabs={{
                        filter: 'status',
                        options: statusOptions(stocktakeStatuses),
                    }}
                    filters={[
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: statusOptions(stocktakeStatuses),
                        },
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
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                    ]}
                    exportable={can('inventory.export')}
                    empty="Chưa có phiếu kiểm hàng nào."
                    actions={
                        can('inventory.create') && (
                            <Button asChild>
                                <Link href="/stocktakes/create">
                                    <Plus /> Tạo phiếu kiểm hàng
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

StocktakesIndex.layout = {
    breadcrumbs: [{ title: 'Kiểm hàng', href: '/stocktakes' }],
};
