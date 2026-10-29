import { Head, Link, router } from '@inertiajs/react';
import { toSelectOptions } from '@/components/app/option-select';
import { Pill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { BulkAction, Column, TableData } from '@/components/data-table';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { ConnectBanner, EinvoiceTabs } from '../../components/einvoice';

type Row = {
    id: number;
    code: string;
    status: string;
    reference: string | null;
    customer: string | null;
    tax_code: string | null;
    branch: string;
    total: number;
    shipped_at: string | null;
    completed_at: string | null;
};

/** "Đơn chưa tạo HĐĐT": exported orders without an invoice yet. */
export default function EinvoicePending({
    orders,
    branches,
    connected,
}: {
    orders: TableData<Row>;
    branches: { id: number; name: string }[];
    connected: boolean;
}) {
    const can = useCan();
    const run = (action: 'create' | 'issue') => (ids: number[]) =>
        router.post(
            '/e-invoices/pending/bulk',
            { action, ids },
            { preserveScroll: true },
        );

    const bulkActions: BulkAction[] = [];

    if (can('einvoices.create')) {
        bulkActions.push({ label: 'Tạo hóa đơn nháp', run: run('create') });

        if (can('einvoices.approve') && connected) {
            bulkActions.push({
                label: 'Tạo và phát hành hóa đơn',
                confirm:
                    'Tạo và phát hành hóa đơn điện tử cho các đơn đã chọn? Hóa đơn đã phát hành không sửa được.',
                run: run('issue'),
            });
        }
    }

    const columns: Column<Row>[] = [
        {
            key: 'code',
            header: 'Mã đơn hàng',
            fixed: true,
            sort: 'code',
            className: 'whitespace-nowrap',
            cell: (row) => (
                <div>
                    <Link
                        href={`/orders/${row.id}`}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.code}
                    </Link>
                    {row.reference && (
                        <div className="text-xs text-muted-foreground">
                            {row.reference}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Trạng thái đơn',
            cell: (row) =>
                row.status === 'completed' ? (
                    <Pill tone="success">Hoàn thành</Pill>
                ) : (
                    <Pill tone="info">Đã xuất kho</Pill>
                ),
        },
        {
            key: 'customer',
            header: 'Khách hàng',
            cell: (row) => (
                <div>
                    <div>{row.customer ?? 'Khách lẻ'}</div>
                    {row.tax_code && (
                        <div className="text-xs text-muted-foreground">
                            MST {row.tax_code}
                        </div>
                    )}
                </div>
            ),
        },
        { key: 'branch', header: 'Chi nhánh', cell: (row) => row.branch },
        {
            key: 'total',
            header: 'Khách phải trả',
            sort: 'total',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.total),
        },
        {
            key: 'shipped_at',
            header: 'Ngày xuất kho',
            sort: 'shipped_at',
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.shipped_at),
        },
        {
            key: 'completed_at',
            header: 'Ngày hoàn thành',
            sort: 'completed_at',
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.completed_at),
        },
    ];

    return (
        <>
            <Head title="Đơn chưa tạo HĐĐT" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <EinvoiceTabs active="pending" />
                {!connected && (
                    <ConnectBanner canConfigure={can('settings.view')} />
                )}
                <DataTable
                    tableKey="einvoice_pending"
                    data={orders}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã đơn, khách hàng, số điện thoại, MST"
                    filters={[
                        {
                            key: 'shipped_at',
                            label: 'Ngày xuất kho',
                            type: 'date-range',
                        },
                        {
                            key: 'status',
                            label: 'Trạng thái đơn',
                            type: 'select',
                            options: [
                                { value: 'shipped', label: 'Đã xuất kho' },
                                { value: 'completed', label: 'Hoàn thành' },
                            ],
                        },
                        {
                            key: 'buyer',
                            label: 'Người mua',
                            type: 'select',
                            options: [
                                {
                                    value: 'company',
                                    label: 'Doanh nghiệp (có MST)',
                                },
                                { value: 'personal', label: 'Cá nhân' },
                            ],
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toSelectOptions(branches),
                        },
                    ]}
                    bulkActions={
                        bulkActions.length > 0 ? bulkActions : undefined
                    }
                    empty="Tất cả đơn đã xuất kho đều đã có hóa đơn điện tử."
                />
            </div>
        </>
    );
}

EinvoicePending.layout = {
    breadcrumbs: [
        { title: 'Đơn hàng', href: '/orders' },
        { title: 'Hóa đơn điện tử', href: '/e-invoices' },
        { title: 'Đơn chưa tạo HĐĐT', href: '/e-invoices/pending' },
    ],
};
