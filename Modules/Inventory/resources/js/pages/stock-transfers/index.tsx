import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { StatusPill, statusOptions } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { transferStatuses } from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    from_branch: string;
    to_branch: string;
    status: string;
    quantity: number;
    value: number | null;
    creator: string | null;
    created_at: string | null;
    shipped_at: string | null;
    received_at: string | null;
};

export default function StockTransfersIndex({
    transfers,
    branches,
}: {
    transfers: TableData<Row>;
    branches: { id: number; name: string }[];
}) {
    const can = useCan();
    const branchOptions = branches.map((branch) => ({
        value: String(branch.id),
        label: branch.name,
    }));

    const columns: Column<Row>[] = [
        {
            key: 'code',
            header: 'Mã phiếu',
            sort: 'code',
            fixed: true,
            className: 'whitespace-nowrap',
            cell: (row) => (
                <RecordLink href={`/stock-transfers/${row.id}`}>
                    {row.code}
                </RecordLink>
            ),
        },
        {
            key: 'from_branch',
            header: 'Chi nhánh chuyển',
            cell: (row) => row.from_branch,
        },
        {
            key: 'to_branch',
            header: 'Chi nhánh nhận',
            cell: (row) => row.to_branch,
        },
        {
            key: 'status',
            header: 'Trạng thái',
            cell: (row) => (
                <StatusPill statuses={transferStatuses} value={row.status} />
            ),
        },
        {
            key: 'quantity',
            header: 'Số lượng',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.quantity),
        },
        ...(can('inventory.view_transfer_price')
            ? [
                  {
                      key: 'value',
                      header: 'Giá trị',
                      className: 'text-right tabular-nums',
                      cell: (row: Row) =>
                          row.value === null ? '' : formatNumber(row.value),
                  },
              ]
            : []),
        {
            key: 'created_at',
            header: 'Ngày tạo',
            sort: 'created_at',
            cell: (row) => formatDateTime(row.created_at),
        },
        {
            key: 'shipped_at',
            header: 'Ngày chuyển',
            sort: 'shipped_at',
            hidden: true,
            cell: (row) => formatDateTime(row.shipped_at),
        },
        {
            key: 'received_at',
            header: 'Ngày nhận',
            sort: 'received_at',
            cell: (row) => formatDateTime(row.received_at),
        },
        {
            key: 'creator',
            header: 'Người tạo',
            hidden: true,
            cell: (row) => row.creator,
        },
    ];

    return (
        <>
            <Head title="Chuyển hàng" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="stock-transfers"
                    data={transfers}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã phiếu, ghi chú"
                    tabs={{
                        filter: 'status',
                        options: statusOptions(transferStatuses),
                    }}
                    filters={[
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: statusOptions(transferStatuses),
                        },
                        {
                            key: 'from_branch_id',
                            label: 'Chi nhánh chuyển',
                            type: 'select',
                            options: branchOptions,
                        },
                        {
                            key: 'to_branch_id',
                            label: 'Chi nhánh nhận',
                            type: 'select',
                            options: branchOptions,
                        },
                        {
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                    ]}
                    exportable={can('inventory.export')}
                    empty="Chưa có phiếu chuyển hàng nào."
                    actions={
                        can('inventory.create') && (
                            <Button asChild>
                                <Link href="/stock-transfers/create">
                                    <Plus /> Tạo phiếu chuyển hàng
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

StockTransfersIndex.layout = {
    breadcrumbs: [{ title: 'Chuyển hàng', href: '/stock-transfers' }],
};
