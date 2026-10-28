import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { StatusPill, statusOptions } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { adjustmentStatuses } from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    branch: string;
    status: string;
    items_count: number;
    note: string | null;
    creator: string | null;
    created_at: string | null;
    applied_at: string | null;
};

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã phiếu',
        sort: 'code',
        fixed: true,
        className: 'whitespace-nowrap',
        cell: (row) => (
            <RecordLink href={`/cost-adjustments/${row.id}`}>
                {row.code}
            </RecordLink>
        ),
    },
    { key: 'branch', header: 'Chi nhánh', cell: (row) => row.branch },
    {
        key: 'status',
        header: 'Trạng thái',
        cell: (row) => (
            <StatusPill statuses={adjustmentStatuses} value={row.status} />
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
        key: 'applied_at',
        header: 'Ngày điều chỉnh',
        sort: 'applied_at',
        cell: (row) => formatDateTime(row.applied_at),
    },
    { key: 'creator', header: 'Người tạo', cell: (row) => row.creator },
    {
        key: 'note',
        header: 'Ghi chú',
        hidden: true,
        cell: (row) => row.note,
    },
];

export default function CostAdjustmentsIndex({
    adjustments,
}: {
    adjustments: TableData<Row>;
}) {
    const can = useCan();

    return (
        <>
            <Head title="Điều chỉnh giá vốn" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="cost-adjustments"
                    data={adjustments}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã phiếu, ghi chú"
                    tabs={{
                        filter: 'status',
                        options: statusOptions(adjustmentStatuses),
                    }}
                    filters={[
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: statusOptions(adjustmentStatuses),
                        },
                        {
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                    ]}
                    empty="Chưa có phiếu điều chỉnh giá vốn nào."
                    actions={
                        can('inventory.create') && (
                            <Button asChild>
                                <Link href="/cost-adjustments/create">
                                    <Plus /> Tạo phiếu điều chỉnh
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

CostAdjustmentsIndex.layout = {
    breadcrumbs: [{ title: 'Điều chỉnh giá vốn', href: '/cost-adjustments' }],
};
