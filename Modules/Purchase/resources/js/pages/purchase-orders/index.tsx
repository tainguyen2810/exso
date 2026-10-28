import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { Pill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import {
    orderStatuses,
    receiveStatuses,
    toOptions,
} from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    supplier: string;
    branch: string;
    status: string;
    receive_status: string;
    expected_at: string | null;
    total: number;
    creator: string | null;
    created_at: string | null;
};

type Option = { id: number; name: string };

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã đơn',
        sort: 'code',
        fixed: true,
        className: 'whitespace-nowrap',
        cell: (row) => (
            <RecordLink href={`/purchase-orders/${row.id}`}>
                {row.code}
            </RecordLink>
        ),
    },
    {
        key: 'created_at',
        header: 'Ngày tạo',
        sort: 'created_at',
        className: 'whitespace-nowrap',
        cell: (row) => formatDateTime(row.created_at),
    },
    { key: 'supplier', header: 'Nhà cung cấp', cell: (row) => row.supplier },
    { key: 'branch', header: 'Chi nhánh', cell: (row) => row.branch },
    {
        key: 'status',
        header: 'Trạng thái',
        cell: (row) => {
            const [label, tone] = orderStatuses[row.status] ?? [row.status];

            return <Pill tone={tone}>{label}</Pill>;
        },
    },
    {
        key: 'receive_status',
        header: 'Nhập hàng',
        cell: (row) => {
            const [label, tone] = receiveStatuses[row.receive_status] ?? [
                row.receive_status,
            ];

            return <Pill tone={tone}>{label}</Pill>;
        },
    },
    {
        key: 'expected_at',
        header: 'Ngày nhận dự kiến',
        sort: 'expected_at',
        cell: (row) => formatDate(row.expected_at),
    },
    {
        key: 'total',
        header: 'Giá trị đơn',
        sort: 'total',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.total),
    },
    {
        key: 'creator',
        header: 'Người tạo',
        hidden: true,
        cell: (row) => row.creator,
    },
];

export default function PurchaseOrdersIndex({
    orders,
    branches,
    suppliers,
}: {
    orders: TableData<Row>;
    branches: Option[];
    suppliers: Option[];
}) {
    const can = useCan();
    const options = (items: Option[]) =>
        items.map((item) => ({ value: String(item.id), label: item.name }));

    return (
        <>
            <Head title="Đặt hàng nhập" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="purchase-orders"
                    data={orders}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã đơn đặt hàng"
                    tabs={{
                        filter: 'status',
                        options: toOptions(orderStatuses),
                    }}
                    filters={[
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: toOptions(orderStatuses),
                        },
                        {
                            key: 'supplier_id',
                            label: 'Nhà cung cấp',
                            type: 'select',
                            options: options(suppliers),
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: options(branches),
                        },
                        {
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                        {
                            key: 'expected_at',
                            label: 'Ngày nhận dự kiến',
                            type: 'date-range',
                        },
                    ]}
                    exportable={can('purchases.export')}
                    empty="Chưa có đơn đặt hàng nhập nào."
                    actions={
                        can('purchases.create') && (
                            <Button asChild>
                                <Link href="/purchase-orders/create">
                                    <Plus /> Tạo đơn đặt hàng
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

PurchaseOrdersIndex.layout = {
    breadcrumbs: [{ title: 'Đặt hàng nhập', href: '/purchase-orders' }],
};
