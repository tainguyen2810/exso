import { Head, Link, router } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { toSelectOptions } from '@/components/app/option-select';
import { statusOptions, StatusPill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import {
    adjustmentLabels,
    ConnectBanner,
    EinvoiceTabs,
    einvoiceKinds,
    einvoiceStatuses,
} from '../../components/einvoice';

type Row = {
    id: number;
    code: string;
    kind: string;
    adjustment_type: string | null;
    original_code: string | null;
    order_id: number | null;
    order_code: string | null;
    status: string;
    error: string | null;
    series: string | null;
    invoice_no: string | null;
    provider: string | null;
    lookup_code: string | null;
    branch: string;
    buyer: string | null;
    buyer_tax_code: string | null;
    total: number;
    issued_at: string | null;
    created_at: string | null;
    payment_method: string;
};

/** Đơn hàng → Hóa đơn điện tử: list like Sapo's. */
export default function EinvoiceIndex({
    invoices,
    branches,
    providers,
    connected,
}: {
    invoices: TableData<Row>;
    branches: { id: number; name: string }[];
    providers: { id: number; name: string }[];
    connected: boolean;
}) {
    const can = useCan();

    const columns: Column<Row>[] = [
        {
            key: 'code',
            header: 'Mã hóa đơn',
            fixed: true,
            sort: 'code',
            className: 'whitespace-nowrap',
            cell: (row) => (
                <div>
                    <Link
                        href={`/e-invoices/${row.id}`}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.code}
                    </Link>
                    {row.kind === 'adjustment' && (
                        <div className="text-xs text-muted-foreground">
                            {adjustmentLabels[row.adjustment_type ?? 'info']}{' '}
                            {row.original_code}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'order',
            header: 'Mã đơn hàng',
            className: 'whitespace-nowrap',
            cell: (row) =>
                row.order_id ? (
                    <Link
                        href={`/orders/${row.order_id}`}
                        className="text-primary hover:underline"
                    >
                        {row.order_code}
                    </Link>
                ) : (
                    '—'
                ),
        },
        {
            key: 'invoice_no',
            header: 'Số hóa đơn',
            className: 'whitespace-nowrap tabular-nums',
            cell: (row) => row.invoice_no ?? '—',
        },
        {
            key: 'status',
            header: 'Trạng thái phát hành',
            cell: (row) => (
                <div className="max-w-64">
                    <StatusPill
                        statuses={einvoiceStatuses}
                        value={row.status}
                    />
                    {row.status === 'failed' && row.error && (
                        <div className="mt-1 text-xs text-destructive">
                            {row.error}
                        </div>
                    )}
                </div>
            ),
        },
        { key: 'series', header: 'Ký hiệu', cell: (row) => row.series ?? '—' },
        {
            key: 'provider',
            header: 'Đơn vị phát hành',
            cell: (row) => row.provider ?? '—',
        },
        {
            key: 'lookup_code',
            header: 'Mã tra cứu',
            cell: (row) => row.lookup_code ?? '—',
        },
        { key: 'branch', header: 'Chi nhánh', cell: (row) => row.branch },
        {
            key: 'buyer',
            header: 'Khách hàng',
            cell: (row) => (
                <div>
                    <div>{row.buyer ?? 'Người mua không lấy hóa đơn'}</div>
                    {row.buyer_tax_code && (
                        <div className="text-xs text-muted-foreground">
                            MST {row.buyer_tax_code}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'total',
            header: 'Tiền khách phải trả',
            sort: 'total',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.total),
        },
        {
            key: 'issued_at',
            header: 'Ngày hóa đơn',
            sort: 'issued_at',
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.issued_at),
        },
        {
            key: 'payment_method',
            header: 'Phương thức thanh toán',
            cell: (row) => row.payment_method,
        },
        {
            key: 'created_at',
            header: 'Ngày tạo',
            sort: 'created_at',
            hidden: true,
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.created_at),
        },
    ];

    return (
        <>
            <Head title="Hóa đơn điện tử" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <EinvoiceTabs active="invoices" />
                {!connected && (
                    <ConnectBanner canConfigure={can('settings.view')} />
                )}
                <DataTable
                    tableKey="einvoices"
                    data={invoices}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã hóa đơn, số hóa đơn, mã đơn hàng, khách hàng, MST"
                    tabs={{
                        filter: 'status',
                        options: statusOptions(einvoiceStatuses),
                    }}
                    filters={[
                        {
                            key: 'issued_at',
                            label: 'Ngày hóa đơn',
                            type: 'date-range',
                        },
                        {
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                        {
                            key: 'kind',
                            label: 'Loại hóa đơn',
                            type: 'select',
                            options: statusOptions(einvoiceKinds),
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toSelectOptions(branches),
                        },
                        {
                            key: 'provider_id',
                            label: 'Đơn vị phát hành',
                            type: 'select',
                            options: toSelectOptions(providers),
                        },
                    ]}
                    canSelect={(row) =>
                        row.status === 'draft' || row.status === 'failed'
                    }
                    bulkActions={
                        can('einvoices.approve')
                            ? [
                                  {
                                      label: 'Phát hành hóa đơn',
                                      confirm:
                                          'Phát hành các hóa đơn đã chọn? Hóa đơn đã phát hành không sửa được.',
                                      run: (ids) =>
                                          router.post(
                                              '/e-invoices/bulk',
                                              { action: 'issue', ids },
                                              { preserveScroll: true },
                                          ),
                                  },
                              ]
                            : undefined
                    }
                    actions={
                        can('settings.view') && (
                            <Button variant="outline" asChild>
                                <Link href="/e-invoices/settings">
                                    <Settings /> Cấu hình hóa đơn
                                </Link>
                            </Button>
                        )
                    }
                    exportable={can('einvoices.export')}
                    empty="Chưa có hóa đơn điện tử nào. Tạo hóa đơn từ trang chi tiết đơn hàng hoặc tab Đơn chưa tạo HĐĐT."
                />
            </div>
        </>
    );
}

EinvoiceIndex.layout = {
    breadcrumbs: [
        { title: 'Đơn hàng', href: '/orders' },
        { title: 'Hóa đơn điện tử', href: '/e-invoices' },
    ],
};
