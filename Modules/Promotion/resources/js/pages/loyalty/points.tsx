import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { CustomerPicker } from '@/components/app/customer-picker';
import type { CustomerOption } from '@/components/app/customer-picker';
import { Field } from '@/components/app/field';
import { RecordLink } from '@/components/app/record-link';
import { RecordDialog } from '@/components/app/settings-page';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    customer: { id: number; code: string; name: string; phone: string | null };
    type: string;
    type_label: string;
    points: number;
    balance_after: number;
    reference_code: string | null;
    reference_url: string | null;
    note: string | null;
    creator: string | null;
    created_at: string | null;
};

type Customer = { id: number; name: string; code: string; points: number };

/** Lịch sử điểm của khách hàng và "Điều chỉnh điểm". */
export default function LoyaltyPoints({
    points,
    types,
    customer,
    enabled,
}: {
    points: TableData<Row>;
    types: Record<string, string>;
    customer: Customer | null;
    enabled: boolean;
}) {
    const can = useCan();
    const [adjusting, setAdjusting] = useState(false);

    const columns: Column<Row>[] = [
        {
            key: 'created_at',
            header: 'Thời gian',
            sort: 'created_at',
            fixed: true,
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.created_at),
        },
        {
            key: 'customer',
            header: 'Khách hàng',
            cell: (row) => (
                <RecordLink href={`/customers/${row.customer.id}`}>
                    {row.customer.name}
                </RecordLink>
            ),
        },
        {
            key: 'type',
            header: 'Loại',
            cell: (row) => row.type_label,
        },
        {
            key: 'reference',
            header: 'Chứng từ',
            cell: (row) =>
                row.reference_url ? (
                    <Link
                        href={row.reference_url}
                        className="text-primary hover:underline"
                    >
                        {row.reference_code}
                    </Link>
                ) : (
                    row.reference_code
                ),
        },
        {
            key: 'points',
            header: 'Điểm thay đổi',
            sort: 'points',
            className: 'text-right tabular-nums',
            cell: (row) => (
                <span
                    className={cn(
                        row.points > 0 ? 'text-success' : 'text-destructive',
                    )}
                >
                    {row.points > 0 ? '+' : ''}
                    {formatNumber(row.points)}
                </span>
            ),
        },
        {
            key: 'balance_after',
            header: 'Điểm sau thay đổi',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.balance_after),
        },
        { key: 'note', header: 'Ghi chú', cell: (row) => row.note },
        {
            key: 'creator',
            header: 'Nhân viên',
            hidden: true,
            cell: (row) => row.creator,
        },
    ];

    return (
        <>
            <Head title="Lịch sử điểm" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                {!enabled && (
                    <div className="mb-4 rounded-md border border-warning-border bg-warning-soft px-4 py-3 text-sm">
                        Cửa hàng chưa bật tích điểm.{' '}
                        <Link
                            href="/loyalty"
                            className="text-primary hover:underline"
                        >
                            Cấu hình tích điểm
                        </Link>
                    </div>
                )}
                {customer && (
                    <div className="mb-4 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg border bg-card px-4 py-3 text-sm">
                        <span>
                            Khách hàng:{' '}
                            <RecordLink href={`/customers/${customer.id}`}>
                                {customer.name}
                            </RecordLink>{' '}
                            <span className="text-muted-foreground">
                                ({customer.code})
                            </span>
                        </span>
                        <span>
                            Điểm hiện tại:{' '}
                            <strong className="tabular-nums">
                                {formatNumber(customer.points)}
                            </strong>
                        </span>
                    </div>
                )}
                <DataTable
                    tableKey="loyalty-points"
                    data={points}
                    columns={columns}
                    searchPlaceholder="Tìm theo tên, SĐT khách hàng, mã chứng từ"
                    empty="Chưa có lịch sử điểm."
                    tabs={{
                        filter: 'type',
                        options: Object.entries(types).map(
                            ([value, label]) => ({ value, label }),
                        ),
                    }}
                    filters={[
                        {
                            key: 'created_at',
                            label: 'Thời gian',
                            type: 'date-range',
                        },
                    ]}
                    keepFilters={['customer_id']}
                    actions={
                        can('loyalty.update') && (
                            <Button onClick={() => setAdjusting(true)}>
                                <Plus /> Điều chỉnh điểm
                            </Button>
                        )
                    }
                />
            </div>
            {adjusting && (
                <AdjustDialog
                    customer={customer}
                    onClose={() => setAdjusting(false)}
                />
            )}
        </>
    );
}

function AdjustDialog({
    customer,
    onClose,
}: {
    customer: Customer | null;
    onClose: () => void;
}) {
    const [picked, setPicked] = useState<CustomerOption | null>(null);
    const form = useForm({
        customer_id: customer?.id ?? (null as number | null),
        points: '',
        note: '',
    });

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title="Điều chỉnh điểm"
            description="Nhập số dương để cộng điểm, số âm để trừ điểm."
            processing={form.processing}
            onSubmit={() =>
                form.post('/loyalty/points', {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            {customer ? (
                <p className="text-sm">
                    Khách hàng: <strong>{customer.name}</strong> (đang có{' '}
                    {formatNumber(customer.points)} điểm)
                </p>
            ) : (
                <Field label="Khách hàng *" error={form.errors.customer_id}>
                    <CustomerPicker
                        value={picked}
                        onChange={(value) => {
                            setPicked(value);
                            form.setData('customer_id', value?.id ?? null);
                        }}
                    />
                </Field>
            )}
            <Field label="Số điểm *" error={form.errors.points}>
                <Input
                    inputMode="numeric"
                    value={form.data.points}
                    placeholder="Ví dụ: 50 hoặc -20"
                    onChange={(event) =>
                        form.setData(
                            'points',
                            event.target.value.replace(/[^\d-]/g, ''),
                        )
                    }
                />
            </Field>
            <Field label="Lý do *" error={form.errors.note}>
                <Textarea
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
        </RecordDialog>
    );
}

LoyaltyPoints.layout = {
    breadcrumbs: [
        { title: 'Khách hàng', href: '/customers' },
        { title: 'Tích điểm khách hàng', href: '/loyalty' },
        { title: 'Lịch sử điểm', href: '/loyalty/points' },
    ],
};
