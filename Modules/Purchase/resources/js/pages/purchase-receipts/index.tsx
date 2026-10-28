import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { Pill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import {
    paymentStatuses,
    receiptStatuses,
    toOptions,
} from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    supplier: string;
    branch: string;
    status: string;
    payment_status: string | null;
    total: number;
    creator: string | null;
    created_at: string | null;
};

type Option = { id: number; name: string };

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã phiếu',
        sort: 'code',
        fixed: true,
        className: 'whitespace-nowrap',
        cell: (row) => (
            <RecordLink href={`/purchase-receipts/${row.id}`}>
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
            const [label, tone] = receiptStatuses[row.status] ?? [row.status];

            return <Pill tone={tone}>{label}</Pill>;
        },
    },
    {
        key: 'payment_status',
        header: 'Thanh toán',
        cell: (row) => {
            if (!row.payment_status) {
                return null;
            }

            const [label, tone] = paymentStatuses[row.payment_status];

            return <Pill tone={tone}>{label}</Pill>;
        },
    },
    {
        key: 'total',
        header: 'Giá trị phiếu',
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

export default function PurchaseReceiptsIndex({
    receipts,
    branches,
    suppliers,
}: {
    receipts: TableData<Row>;
    branches: Option[];
    suppliers: Option[];
}) {
    const can = useCan();
    const options = (items: Option[]) =>
        items.map((item) => ({ value: String(item.id), label: item.name }));

    return (
        <>
            <Head title="Nhập hàng" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="purchase-receipts"
                    data={receipts}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã phiếu nhập, mã tham chiếu"
                    tabs={{
                        filter: 'status',
                        options: toOptions(receiptStatuses),
                    }}
                    filters={[
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: toOptions(receiptStatuses),
                        },
                        {
                            key: 'payment',
                            label: 'Thanh toán',
                            type: 'select',
                            options: toOptions(paymentStatuses),
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
                    ]}
                    exportable={can('purchases.export')}
                    empty="Chưa có phiếu nhập hàng nào."
                    actions={
                        can('purchases.create') && (
                            <Button asChild>
                                <Link href="/purchase-receipts/create">
                                    <Plus /> Tạo phiếu nhập hàng
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

PurchaseReceiptsIndex.layout = {
    breadcrumbs: [{ title: 'Nhập hàng', href: '/purchase-receipts' }],
};
