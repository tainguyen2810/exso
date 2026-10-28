import { Head, Link, router } from '@inertiajs/react';
import { Equal, Minus, Plus } from 'lucide-react';
import { toSelectOptions } from '@/components/app/option-select';
import { PeriodPicker } from '@/components/app/period-picker';
import { RecordLink } from '@/components/app/record-link';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Card } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    code: string;
    kind: 'receipt' | 'payment';
    type: string;
    amount: number;
    method_name: string;
    party_name: string;
    document_code: string | null;
    branch: string;
    creator: string | null;
    recorded_at: string;
};

type Option = { id: number; name: string };

type Summary = {
    opening: number;
    receipts: number;
    payments: number;
    closing: number;
};

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã phiếu',
        sort: 'code',
        fixed: true,
        className: 'whitespace-nowrap',
        cell: (row) => (
            <RecordLink href={`/cashbook/vouchers/${row.id}`}>
                {row.code}
            </RecordLink>
        ),
    },
    { key: 'type', header: 'Loại phiếu', cell: (row) => row.type },
    {
        key: 'receipt',
        header: 'Tiền thu',
        className: 'text-right tabular-nums',
        cell: (row) =>
            row.kind === 'receipt' ? formatNumber(row.amount) : '-',
    },
    {
        key: 'payment',
        header: 'Tiền chi',
        className: 'text-right tabular-nums',
        cell: (row) =>
            row.kind === 'payment' ? formatNumber(row.amount) : '-',
    },
    {
        key: 'method',
        header: 'Hình thức thanh toán',
        cell: (row) => row.method_name,
    },
    {
        key: 'recorded_at',
        header: 'Ngày ghi nhận',
        sort: 'recorded_at',
        className: 'whitespace-nowrap',
        cell: (row) => formatDateTime(row.recorded_at),
    },
    {
        key: 'document_code',
        header: 'Mã chứng từ',
        cell: (row) => row.document_code,
    },
    {
        key: 'party',
        header: 'Đối tượng',
        hidden: true,
        cell: (row) => row.party_name,
    },
    {
        key: 'branch',
        header: 'Chi nhánh',
        hidden: true,
        cell: (row) => row.branch,
    },
    {
        key: 'creator',
        header: 'Người tạo',
        hidden: true,
        cell: (row) => row.creator,
    },
];

/** Sổ quỹ like Sapo: đầu kỳ + thu − chi = cuối kỳ over the chosen period. */
export default function Cashbook({
    vouchers,
    summary,
    period,
    funds,
    branches,
    paymentMethods,
    staff,
}: {
    vouchers: TableData<Row>;
    summary: Summary;
    period: { from: string; to: string };
    funds: Record<string, string>;
    branches: Option[];
    paymentMethods: Option[];
    staff: Option[];
}) {
    const can = useCan();

    const setPeriod = (next: { from: string; to: string }) => {
        router.get(
            window.location.pathname,
            {
                search: vouchers.query.search || undefined,
                filter: { ...vouchers.query.filter, recorded_at: next },
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const figure = (label: string, value: number, tone?: string) => (
        <div className="min-w-32 text-center">
            <p className="text-sm font-medium">{label}</p>
            <p className={cn('text-base tabular-nums', tone)}>
                {formatNumber(value)}
            </p>
        </div>
    );
    const sign = (Icon: typeof Plus) => (
        <Icon className="size-4 shrink-0 text-muted-foreground" />
    );

    return (
        <>
            <Head title="Sổ quỹ" />
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div className="flex flex-wrap items-center gap-3">
                    <PeriodPicker
                        label="Ngày ghi nhận"
                        value={period}
                        onChange={setPeriod}
                    />
                    <div className="ml-auto flex gap-4 text-sm">
                        {can('cashbook.view') && (
                            <>
                                <Link
                                    href="/cashbook/receipts"
                                    className="hover:text-primary"
                                >
                                    Phiếu thu
                                </Link>
                                <Link
                                    href="/cashbook/payments"
                                    className="hover:text-primary"
                                >
                                    Phiếu chi
                                </Link>
                            </>
                        )}
                    </div>
                </div>
                <Card className="flex-row flex-wrap items-center justify-around gap-4 px-6 py-4">
                    {figure('Số dư đầu kỳ', summary.opening)}
                    {sign(Plus)}
                    {figure('Tổng thu', summary.receipts, 'text-success')}
                    {sign(Minus)}
                    {figure('Tổng chi', summary.payments, 'text-destructive')}
                    {sign(Equal)}
                    {figure(
                        'Tồn cuối kỳ',
                        summary.closing,
                        'text-primary font-semibold',
                    )}
                </Card>
                <DataTable
                    tableKey="cashbook"
                    data={vouchers}
                    columns={columns}
                    searchPlaceholder="Tìm kiếm theo mã phiếu, mã chứng từ gốc, đối tượng, tag"
                    keepFilters={['recorded_at']}
                    tabs={{
                        filter: 'kind',
                        options: [
                            { value: 'payment', label: 'Phiếu chi' },
                            { value: 'receipt', label: 'Phiếu thu' },
                        ],
                    }}
                    filters={[
                        {
                            key: 'fund',
                            label: 'Quỹ',
                            type: 'select',
                            options: Object.entries(funds).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toSelectOptions(branches),
                        },
                        {
                            key: 'payment_method_id',
                            label: 'Hình thức thanh toán',
                            type: 'select',
                            options: toSelectOptions(paymentMethods),
                        },
                        {
                            key: 'created_by',
                            label: 'Người tạo',
                            type: 'select',
                            options: toSelectOptions(staff),
                        },
                    ]}
                    exportable={can('cashbook.export')}
                    empty="Không có phiếu thu, chi nào trong kỳ."
                />
            </div>
        </>
    );
}

Cashbook.layout = {
    breadcrumbs: [{ title: 'Sổ quỹ', href: '/cashbook' }],
};
