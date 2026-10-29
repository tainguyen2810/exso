import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { DocumentView } from '@/components/app/document-view';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { RecordDialog } from '@/components/app/settings-page';
import { Pill, StatusPill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    reconciliationStatuses,
    shipmentStatuses,
} from '../../components/statuses';

type Item = {
    id: number;
    shipment_id: number;
    shipment_code: string;
    tracking_code: string | null;
    shipment_status: string;
    order_id: number;
    order_code: string;
    cod: number;
    fee: number;
    partner_cod: number;
    partner_fee: number;
    paid: number;
    differs: boolean;
};

type Reconciliation = {
    id: number;
    code: string;
    status: string;
    carrier: { id: number; name: string };
    branch: string;
    creator: string | null;
    completer: string | null;
    payment_method: string | null;
    note: string | null;
    tags: string | null;
    created_at: string | null;
    completed_at: string | null;
    cod_total: number;
    fee_total: number;
    partner_cod_total: number;
    partner_fee_total: number;
    system_amount: number;
    amount: number;
    items: Item[];
};

const TABS = [
    ['all', 'Danh sách vận đơn'],
    ['differs', 'Vận đơn chênh lệch số liệu'],
    ['matches', 'Vận đơn đúng số liệu'],
] as const;

/**
 * Chi tiết phiếu đối soát like Sapo: system and partner figures with the
 * difference, shipments split by whether they match, "Thanh toán công nợ".
 */
export default function CodReconciliationShow({
    reconciliation,
    paymentMethods,
}: {
    reconciliation: Reconciliation;
    paymentMethods: { id: number; name: string; type: string }[];
}) {
    const can = useCan();
    const [tab, setTab] = useState<(typeof TABS)[number][0]>('all');
    const [paying, setPaying] = useState(false);
    const [editing, setEditing] = useState<Item | null>(null);
    const open = reconciliation.status === 'reconciling';
    const items = reconciliation.items.filter((item) =>
        tab === 'all' ? true : tab === 'differs' ? item.differs : !item.differs,
    );
    const differs = reconciliation.items.filter((item) => item.differs).length;

    return (
        <>
            <Head title={`Phiếu đối soát ${reconciliation.code}`} />
            <DocumentView
                title={reconciliation.code}
                badges={
                    <StatusPill
                        statuses={reconciliationStatuses}
                        value={reconciliation.status}
                    />
                }
                meta={`${formatDateTime(reconciliation.created_at)} · ${reconciliation.branch}${reconciliation.creator ? ` · ${reconciliation.creator}` : ''}`}
                backHref="/cod-reconciliations"
                backLabel="Quay lại danh sách phiếu đối soát"
                actions={
                    open && (
                        <>
                            {can('cod_reconciliations.cancel') && (
                                <ConfirmAction
                                    url={`/cod-reconciliations/${reconciliation.id}/cancel`}
                                    title="Hủy phiếu đối soát?"
                                    description="Các vận đơn trong phiếu quay lại trạng thái Chờ đối soát."
                                    confirmLabel="Hủy phiếu"
                                    destructive
                                    trigger={
                                        <Button
                                            variant="outline"
                                            className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                        >
                                            Hủy phiếu
                                        </Button>
                                    }
                                />
                            )}
                            {can('cod_reconciliations.approve') && (
                                <Button onClick={() => setPaying(true)}>
                                    Thanh toán công nợ
                                </Button>
                            )}
                        </>
                    )
                }
                aside={
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b px-5 py-4">
                            <CardTitle>Thông tin phiếu</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 px-5 py-4 text-sm">
                            <Line label="Đối tác">
                                {reconciliation.carrier.name}
                            </Line>
                            <Line label="Chi nhánh">
                                {reconciliation.branch}
                            </Line>
                            <Line label="Người tạo">
                                {reconciliation.creator ?? '—'}
                            </Line>
                            {reconciliation.completed_at && (
                                <>
                                    <Line label="Ngày đối soát">
                                        {formatDateTime(
                                            reconciliation.completed_at,
                                        )}
                                    </Line>
                                    <Line label="Người đối soát">
                                        {reconciliation.completer ?? '—'}
                                    </Line>
                                    <Line label="Hình thức nhận tiền">
                                        {reconciliation.payment_method ?? '—'}
                                    </Line>
                                </>
                            )}
                            {reconciliation.note && (
                                <Line label="Ghi chú">
                                    {reconciliation.note}
                                </Line>
                            )}
                            {reconciliation.tags && (
                                <div className="flex flex-wrap gap-1.5">
                                    {reconciliation.tags
                                        .split(',')
                                        .map((tag) => (
                                            <Pill key={tag}>{tag.trim()}</Pill>
                                        ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                }
            >
                <Card className="gap-0 py-0">
                    <div className="grid divide-y text-sm sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                        <Figure
                            title="Đối soát hệ thống"
                            rows={[
                                ['COD', reconciliation.cod_total],
                                ['Phí giao hàng', reconciliation.fee_total],
                            ]}
                            total={reconciliation.system_amount}
                        />
                        <Figure
                            title="Đối soát đối tác"
                            rows={[
                                ['COD', reconciliation.partner_cod_total],
                                [
                                    'Phí giao hàng',
                                    reconciliation.partner_fee_total,
                                ],
                            ]}
                            total={reconciliation.amount}
                        />
                        <Figure
                            title="Chênh lệch"
                            rows={[
                                [
                                    'COD',
                                    reconciliation.partner_cod_total -
                                        reconciliation.cod_total,
                                ],
                                [
                                    'Phí giao hàng',
                                    reconciliation.partner_fee_total -
                                        reconciliation.fee_total,
                                ],
                            ]}
                            total={
                                reconciliation.amount -
                                reconciliation.system_amount
                            }
                            alert
                        />
                    </div>
                </Card>

                <Card className="gap-0 py-0">
                    <div className="flex gap-5 border-b px-5 text-sm">
                        {TABS.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setTab(key)}
                                className={cn(
                                    '-mb-px border-b-2 py-3',
                                    tab === key
                                        ? 'border-primary font-medium text-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                                {key === 'differs' &&
                                    differs > 0 &&
                                    ` (${differs})`}
                            </button>
                        ))}
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-5 py-2.5 font-normal">
                                        Mã vận đơn
                                    </th>
                                    <th className="px-3 py-2.5 font-normal">
                                        Đơn hàng
                                    </th>
                                    <th className="px-3 py-2.5 text-right font-normal">
                                        COD hệ thống
                                    </th>
                                    <th className="px-3 py-2.5 text-right font-normal">
                                        Phí hệ thống
                                    </th>
                                    <th className="px-3 py-2.5 text-right font-normal">
                                        COD đối tác
                                    </th>
                                    <th className="px-3 py-2.5 text-right font-normal">
                                        Phí đối tác
                                    </th>
                                    {!open && (
                                        <th className="px-5 py-2.5 text-right font-normal">
                                            Đã ghi thu
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item) => (
                                    <tr key={item.id} className="border-t">
                                        <td className="px-5 py-2.5">
                                            <Link
                                                href={`/shipments/${item.shipment_id}`}
                                                className="text-primary hover:underline"
                                            >
                                                {item.tracking_code ??
                                                    item.shipment_code}
                                            </Link>
                                            <div className="mt-0.5">
                                                <StatusPill
                                                    statuses={shipmentStatuses}
                                                    value={item.shipment_status}
                                                />
                                            </div>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <Link
                                                href={`/orders/${item.order_id}`}
                                                className="text-primary hover:underline"
                                            >
                                                {item.order_code}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {formatNumber(item.cod)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {formatNumber(item.fee)}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-3 py-2.5 text-right tabular-nums',
                                                item.partner_cod !== item.cod &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {open &&
                                            can(
                                                'cod_reconciliations.create',
                                            ) ? (
                                                <button
                                                    type="button"
                                                    className="hover:underline"
                                                    onClick={() =>
                                                        setEditing(item)
                                                    }
                                                >
                                                    {formatNumber(
                                                        item.partner_cod,
                                                    )}
                                                </button>
                                            ) : (
                                                formatNumber(item.partner_cod)
                                            )}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-3 py-2.5 text-right tabular-nums',
                                                item.partner_fee !== item.fee &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {open &&
                                            can(
                                                'cod_reconciliations.create',
                                            ) ? (
                                                <button
                                                    type="button"
                                                    className="hover:underline"
                                                    onClick={() =>
                                                        setEditing(item)
                                                    }
                                                >
                                                    {formatNumber(
                                                        item.partner_fee,
                                                    )}
                                                </button>
                                            ) : (
                                                formatNumber(item.partner_fee)
                                            )}
                                        </td>
                                        {!open && (
                                            <td className="px-5 py-2.5 text-right tabular-nums">
                                                {formatNumber(item.paid)}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                                {items.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-5 py-6 text-center text-muted-foreground"
                                        >
                                            Không có vận đơn.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </DocumentView>

            <PayDialog
                open={paying}
                onOpenChange={setPaying}
                reconciliation={reconciliation}
                methods={paymentMethods}
            />
            {editing && (
                <ItemDialog
                    key={editing.id}
                    item={editing}
                    reconciliationId={reconciliation.id}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function Figure({
    title,
    rows,
    total,
    alert = false,
}: {
    title: string;
    rows: [string, number][];
    total: number;
    alert?: boolean;
}) {
    return (
        <div className="grid gap-1.5 px-5 py-4">
            <div className="font-medium">{title}</div>
            {rows.map(([label, value]) => (
                <div key={label} className="flex justify-between gap-3">
                    <span className="text-muted-foreground">{label}</span>
                    <span
                        className={cn(
                            'tabular-nums',
                            alert && value !== 0 && 'text-destructive',
                        )}
                    >
                        {formatNumber(value)}
                    </span>
                </div>
            ))}
            <div className="flex justify-between gap-3 border-t pt-1.5 font-semibold">
                <span>{alert ? 'Tổng chênh lệch' : 'Đối tác phải trả'}</span>
                <span
                    className={cn(
                        'tabular-nums',
                        alert && total !== 0 && 'text-destructive',
                    )}
                >
                    {formatNumber(total)}
                </span>
            </div>
        </div>
    );
}

function PayDialog({
    open,
    onOpenChange,
    reconciliation,
    methods,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reconciliation: Reconciliation;
    methods: { id: number; name: string; type: string }[];
}) {
    const form = useForm({
        payment_method_id: ((
            methods.find((method) => method.type === 'bank') ?? methods[0]
        )?.id ?? null) as number | null,
    });

    return (
        <RecordDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Thanh toán công nợ"
            description={`${reconciliation.carrier.name} trả ${formatNumber(reconciliation.amount)} đ (COD ${formatNumber(reconciliation.partner_cod_total)} trừ phí ${formatNumber(reconciliation.partner_fee_total)}). Tiền COD được ghi thanh toán cho từng đơn hàng, phí giao hàng ghi phiếu chi ở Sổ quỹ.`}
            processing={form.processing}
            onSubmit={() =>
                form.post(
                    `/cod-reconciliations/${reconciliation.id}/complete`,
                    {
                        preserveScroll: true,
                        onSuccess: () => onOpenChange(false),
                    },
                )
            }
        >
            <Field
                label="Hình thức nhận tiền"
                error={form.errors.payment_method_id}
            >
                <OptionSelect
                    value={
                        form.data.payment_method_id
                            ? String(form.data.payment_method_id)
                            : null
                    }
                    onChange={(value) =>
                        form.setData(
                            'payment_method_id',
                            value ? Number(value) : null,
                        )
                    }
                    options={toSelectOptions(methods)}
                />
            </Field>
        </RecordDialog>
    );
}

function ItemDialog({
    item,
    reconciliationId,
    onClose,
}: {
    item: Item;
    reconciliationId: number;
    onClose: () => void;
}) {
    const [values, setValues] = useState({
        partner_cod: item.partner_cod,
        partner_fee: item.partner_fee,
    });
    const [processing, setProcessing] = useState(false);

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Số liệu đối tác: ${item.tracking_code ?? item.shipment_code}`}
            description={`Hệ thống: COD ${formatNumber(item.cod)}, phí ${formatNumber(item.fee)}.`}
            processing={processing}
            onSubmit={() =>
                router.put(
                    `/cod-reconciliations/${reconciliationId}/items/${item.id}`,
                    values,
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                        onSuccess: onClose,
                    },
                )
            }
        >
            <div className="grid grid-cols-2 gap-3">
                <Field label="COD đối tác">
                    <MoneyInput
                        value={values.partner_cod}
                        onValueChange={(value) =>
                            setValues({ ...values, partner_cod: value ?? 0 })
                        }
                    />
                </Field>
                <Field label="Phí đối tác">
                    <MoneyInput
                        value={values.partner_fee}
                        onValueChange={(value) =>
                            setValues({ ...values, partner_fee: value ?? 0 })
                        }
                    />
                </Field>
            </div>
        </RecordDialog>
    );
}

function Line({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{children}</span>
        </div>
    );
}

CodReconciliationShow.layout = {
    breadcrumbs: [
        { title: 'Đối soát COD và phí', href: '/cod-reconciliations' },
        { title: 'Chi tiết phiếu đối soát', href: '#' },
    ],
};
