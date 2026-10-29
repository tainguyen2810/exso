import { Head, Link } from '@inertiajs/react';
import { toSelectOptions } from '@/components/app/option-select';
import { RecordLink } from '@/components/app/record-link';
import { StatusPill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import {
    codStatuses,
    shipmentStatuses,
    toOptions,
} from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    tracking_code: string | null;
    order_id: number;
    order_code: string;
    status: string;
    cod_status: string;
    carrier: string;
    receiver_name: string;
    receiver_phone: string;
    address: string;
    branch: string;
    fee: number;
    cod_amount: number;
    created_at: string | null;
    delivered_at: string | null;
};

type Option = { id: number; name: string };

/** "Quản lý vận đơn" like Sapo: tabs per delivery status, COD and fees. */
export default function ShipmentsIndex({
    shipments,
    carriers,
    branches,
}: {
    shipments: TableData<Row>;
    carriers: Option[];
    branches: Option[];
}) {
    const can = useCan();

    const columns: Column<Row>[] = [
        {
            key: 'code',
            header: 'Mã đóng gói',
            sort: 'code',
            fixed: true,
            className: 'whitespace-nowrap',
            cell: (row) => (
                <RecordLink href={`/shipments/${row.id}`}>
                    {row.code}
                </RecordLink>
            ),
        },
        {
            key: 'tracking_code',
            header: 'Mã vận đơn',
            className: 'whitespace-nowrap',
            cell: (row) => row.tracking_code ?? '—',
        },
        {
            key: 'order',
            header: 'Mã đơn hàng',
            className: 'whitespace-nowrap',
            cell: (row) => (
                <Link
                    href={`/orders/${row.order_id}`}
                    className="text-primary hover:underline"
                >
                    {row.order_code}
                </Link>
            ),
        },
        {
            key: 'created_at',
            header: 'Ngày đóng gói',
            sort: 'created_at',
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.created_at),
        },
        {
            key: 'status',
            header: 'Trạng thái giao hàng',
            cell: (row) => (
                <StatusPill statuses={shipmentStatuses} value={row.status} />
            ),
        },
        {
            key: 'receiver',
            header: 'Người nhận',
            cell: (row) => (
                <div>
                    <div>{row.receiver_name}</div>
                    <div className="text-xs text-muted-foreground">
                        {row.receiver_phone}
                    </div>
                </div>
            ),
        },
        {
            key: 'carrier',
            header: 'Đối tác giao hàng',
            cell: (row) => row.carrier,
        },
        {
            key: 'cod_amount',
            header: 'Tiền thu hộ COD',
            sort: 'cod_amount',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.cod_amount),
        },
        {
            key: 'fee',
            header: 'Phí trả ĐTVC',
            sort: 'fee',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.fee),
        },
        {
            key: 'cod_status',
            header: 'Đối soát',
            cell: (row) => (
                <StatusPill statuses={codStatuses} value={row.cod_status} />
            ),
        },
        {
            key: 'address',
            header: 'Địa chỉ giao hàng',
            hidden: true,
            cell: (row) => row.address,
        },
        {
            key: 'branch',
            header: 'Chi nhánh',
            hidden: true,
            cell: (row) => row.branch,
        },
        {
            key: 'delivered_at',
            header: 'Ngày giao hàng',
            sort: 'delivered_at',
            hidden: true,
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.delivered_at),
        },
    ];

    return (
        <>
            <Head title="Quản lý vận đơn" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="shipments"
                    data={shipments}
                    columns={columns}
                    searchPlaceholder="Tìm kiếm theo mã đóng gói, mã vận đơn, mã đơn hàng, tên, SĐT người nhận"
                    tabs={{
                        filter: 'status',
                        options: toOptions(shipmentStatuses),
                    }}
                    filters={[
                        {
                            key: 'created_at',
                            label: 'Ngày đóng gói',
                            type: 'date-range',
                        },
                        {
                            key: 'delivered_at',
                            label: 'Ngày giao hàng',
                            type: 'date-range',
                        },
                        {
                            key: 'carrier_id',
                            label: 'Đối tác vận chuyển',
                            type: 'select',
                            options: toSelectOptions(carriers),
                        },
                        {
                            key: 'cod_status',
                            label: 'Trạng thái đối soát',
                            type: 'select',
                            options: toOptions(codStatuses).filter(
                                (option) => option.value !== 'none',
                            ),
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toSelectOptions(branches),
                        },
                        {
                            key: 'status',
                            label: 'Trạng thái giao hàng',
                            type: 'select',
                            options: toOptions(shipmentStatuses),
                        },
                    ]}
                    exportable={can('shipments.export')}
                    empty="Chưa có vận đơn nào. Tạo vận đơn ở trang chi tiết đơn hàng (Giao hàng)."
                />
            </div>
        </>
    );
}

ShipmentsIndex.layout = {
    breadcrumbs: [{ title: 'Quản lý vận đơn', href: '/shipments' }],
};
