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
import { cn } from '@/lib/utils';
import { reconciliationStatuses, toOptions } from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    status: string;
    carrier: string;
    branch: string;
    shipments_count: number;
    system_amount: number;
    amount: number;
    creator: string | null;
    created_at: string | null;
    completed_at: string | null;
};

type Option = { id: number; name: string };

/** "Đối soát COD và phí" like Sapo: system figures next to the partner's. */
export default function CodReconciliationsIndex({
    reconciliations,
    carriers,
    branches,
}: {
    reconciliations: TableData<Row>;
    carriers: Option[];
    branches: Option[];
}) {
    const can = useCan();

    const columns: Column<Row>[] = [
        {
            key: 'code',
            header: 'Mã phiếu',
            sort: 'code',
            fixed: true,
            className: 'whitespace-nowrap',
            cell: (row) => (
                <RecordLink href={`/cod-reconciliations/${row.id}`}>
                    {row.code}
                </RecordLink>
            ),
        },
        {
            key: 'created_at',
            header: 'Ngày tạo phiếu',
            sort: 'created_at',
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.created_at),
        },
        {
            key: 'carrier',
            header: 'Đối tác vận chuyển',
            cell: (row) => row.carrier,
        },
        {
            key: 'status',
            header: 'Trạng thái',
            cell: (row) => (
                <StatusPill
                    statuses={reconciliationStatuses}
                    value={row.status}
                />
            ),
        },
        {
            key: 'shipments_count',
            header: 'Số vận đơn',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.shipments_count),
        },
        {
            key: 'system_amount',
            header: 'Đối soát hệ thống',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.system_amount),
        },
        {
            key: 'amount',
            header: 'Đối soát đối tác',
            sort: 'amount',
            className: 'text-right tabular-nums',
            cell: (row) => (
                <span
                    className={cn(
                        row.amount !== row.system_amount && 'text-destructive',
                    )}
                >
                    {formatNumber(row.amount)}
                </span>
            ),
        },
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
            key: 'completed_at',
            header: 'Ngày đối soát',
            hidden: true,
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.completed_at),
        },
    ];

    return (
        <>
            <Head title="Đối soát COD và phí" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="cod-reconciliations"
                    data={reconciliations}
                    columns={columns}
                    searchPlaceholder="Tìm kiếm theo mã phiếu đối soát, ghi chú"
                    tabs={{
                        filter: 'status',
                        options: toOptions(reconciliationStatuses),
                    }}
                    filters={[
                        {
                            key: 'created_at',
                            label: 'Ngày tạo phiếu',
                            type: 'date-range',
                        },
                        {
                            key: 'carrier_id',
                            label: 'Đối tác vận chuyển',
                            type: 'select',
                            options: toSelectOptions(carriers),
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toSelectOptions(branches),
                        },
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: toOptions(reconciliationStatuses),
                        },
                    ]}
                    exportable={can('cod_reconciliations.export')}
                    empty="Chưa có phiếu đối soát nào."
                    actions={
                        can('cod_reconciliations.create') && (
                            <Button asChild>
                                <Link href="/cod-reconciliations/create">
                                    <Plus /> Tạo phiếu đối soát
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

CodReconciliationsIndex.layout = {
    breadcrumbs: [
        { title: 'Đối soát COD và phí', href: '/cod-reconciliations' },
    ],
};
