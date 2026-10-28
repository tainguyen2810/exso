import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { toSelectOptions } from '@/components/app/option-select';
import { RecordLink } from '@/components/app/record-link';
import { StatusPill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { refundStatuses, toOptions } from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    order: { id: number; code: string };
    customer: { id: number; name: string } | null;
    branch: string;
    status: string;
    refund_status: string;
    quantity: number;
    total: number;
    refunded: number;
    reason: string | null;
    creator: string | null;
    received_at: string | null;
    created_at: string | null;
};

type Option = { id: number; name: string };

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã đơn trả hàng',
        sort: 'code',
        fixed: true,
        className: 'whitespace-nowrap',
        cell: (row) => (
            <RecordLink href={`/returns/${row.id}`}>{row.code}</RecordLink>
        ),
    },
    {
        key: 'order',
        header: 'Mã đơn hàng',
        cell: (row) => (
            <Link
                href={`/orders/${row.order.id}`}
                className="hover:text-primary"
            >
                {row.order.code}
            </Link>
        ),
    },
    {
        key: 'customer',
        header: 'Khách hàng',
        cell: (row) => row.customer?.name ?? 'Khách lẻ',
    },
    {
        key: 'status',
        header: 'Trạng thái nhận hàng',
        cell: () => (
            <StatusPill
                statuses={{ received: ['Đã nhận hàng', 'success'] }}
                value="received"
            />
        ),
    },
    {
        key: 'refund',
        header: 'Trạng thái hoàn tiền',
        cell: (row) => (
            <StatusPill statuses={refundStatuses} value={row.refund_status} />
        ),
    },
    {
        key: 'total',
        header: 'Tổng tiền hàng trả',
        sort: 'total',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.total),
    },
    {
        key: 'refunded',
        header: 'Đã hoàn',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.refunded),
    },
    { key: 'reason', header: 'Lý do', hidden: true, cell: (row) => row.reason },
    {
        key: 'branch',
        header: 'Chi nhánh',
        hidden: true,
        cell: (row) => row.branch,
    },
    {
        key: 'creator',
        header: 'Nhân viên tạo',
        hidden: true,
        cell: (row) => row.creator,
    },
    {
        key: 'created_at',
        header: 'Ngày tạo',
        sort: 'created_at',
        className: 'whitespace-nowrap',
        cell: (row) => formatDateTime(row.created_at),
    },
];

export default function ReturnsIndex({
    returns,
    branches,
    reasons,
    staff,
}: {
    returns: TableData<Row>;
    branches: Option[];
    reasons: Option[];
    staff: Option[];
}) {
    const can = useCan();

    return (
        <>
            <Head title="Khách trả hàng" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="returns"
                    data={returns}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã đơn trả, mã đơn hàng, tên, SĐT khách"
                    tabs={{
                        filter: 'refund',
                        options: toOptions(refundStatuses),
                    }}
                    filters={[
                        {
                            key: 'refund',
                            label: 'Trạng thái hoàn tiền',
                            type: 'select',
                            options: toOptions(refundStatuses),
                        },
                        {
                            key: 'reason_id',
                            label: 'Lý do trả',
                            type: 'select',
                            options: toSelectOptions(reasons),
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toSelectOptions(branches),
                        },
                        {
                            key: 'created_by',
                            label: 'Nhân viên tạo',
                            type: 'select',
                            options: toSelectOptions(staff),
                        },
                        {
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                    ]}
                    exportable={can('returns.export')}
                    empty="Chưa có đơn trả hàng nào."
                    actions={
                        can('returns.create') && (
                            <Button asChild>
                                <Link href="/returns/create">
                                    <Plus /> Tạo đơn trả hàng
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

ReturnsIndex.layout = {
    breadcrumbs: [{ title: 'Khách trả hàng', href: '/returns' }],
};
