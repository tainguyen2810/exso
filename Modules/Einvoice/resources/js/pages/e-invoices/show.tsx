import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { DocumentView } from '@/components/app/document-view';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect } from '@/components/app/option-select';
import type { SelectOption } from '@/components/app/option-select';
import { RecordDialog } from '@/components/app/settings-page';
import { StatusPill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { adjustmentLabels, einvoiceStatuses } from '../../components/einvoice';

type Line = {
    variant_id: number | null;
    code: string | null;
    name: string;
    unit: string | null;
    quantity: number;
    price: number;
    discount: number;
    tax_percent: number;
    is_promotion: boolean;
};

type Invoice = {
    id: number;
    code: string;
    kind: 'original' | 'adjustment';
    adjustment_type: string | null;
    original: { id: number; code: string; number: string | null } | null;
    status: string;
    editable: boolean;
    error: string | null;
    order: { id: number; code: string } | null;
    branch: string;
    provider: string | null;
    template_code: string | null;
    series: string | null;
    seller_tax_code: string | null;
    invoice_no: string | null;
    lookup_code: string | null;
    issued_at: string | null;
    created_at: string | null;
    creator: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    buyer_name: string | null;
    buyer_company: string | null;
    buyer_tax_code: string | null;
    buyer_address: string | null;
    buyer_email: string | null;
    buyer_phone: string | null;
    buyer_id_number: string | null;
    buyer_bank_account: string | null;
    buyer_bank_name: string | null;
    payment_method: string;
    note: string | null;
    subtotal: number;
    discount: number;
    amount_before_tax: number;
    tax_amount: number;
    total: number;
    total_words: string;
    items: (Line & {
        id: number;
        amount: number;
        amount_before_tax: number;
        tax_amount: number;
        amount_after_tax: number;
    })[];
    adjustments: {
        id: number;
        code: string;
        status: string;
        type: string;
        invoice_no: string | null;
        total: number;
    }[];
};

const BUYER_FIELDS = [
    ['buyer_name', 'Họ tên người mua'],
    ['buyer_company', 'Tên đơn vị'],
    ['buyer_tax_code', 'Mã số thuế'],
    ['buyer_address', 'Địa chỉ'],
    ['buyer_email', 'Email nhận hóa đơn'],
    ['buyer_phone', 'Số điện thoại'],
    ['buyer_id_number', 'Số CCCD/Hộ chiếu'],
    ['buyer_bank_account', 'Số tài khoản'],
    ['buyer_bank_name', 'Ngân hàng'],
] as const;

type BuyerKey = (typeof BUYER_FIELDS)[number][0];

type FormData = Record<BuyerKey, string> & {
    payment_method: string;
    note: string;
    items: Line[];
    issue: boolean;
};

/** Same formula as EinvoiceService::lineAmounts. */
function amounts(line: Line, includesTax: boolean) {
    const gross = line.quantity * line.price;
    const amount = Math.max(
        0,
        gross - Math.min(Math.max(0, line.discount), gross),
    );
    const rate = Math.max(0, line.tax_percent);

    if (rate === 0) {
        return { before: amount, tax: 0, after: amount };
    }

    if (includesTax) {
        const before = Math.floor(
            (amount * 100 + Math.floor((100 + rate) / 2)) / (100 + rate),
        );

        return { before, tax: amount - before, after: amount };
    }

    const tax = Math.floor((amount * rate + 50) / 100);

    return { before: amount, tax, after: amount + tax };
}

/** Hóa đơn điện tử: VAT invoice form like Sapo's, editable until issued. */
export default function EinvoiceShow({
    invoice,
    taxRates,
    adjustmentTypes,
    paymentMethods,
    priceIncludesTax,
    connected,
    canPdf,
}: {
    invoice: Invoice;
    taxRates: SelectOption[];
    adjustmentTypes: SelectOption[];
    paymentMethods: string[];
    priceIncludesTax: boolean;
    connected: boolean;
    canPdf: boolean;
}) {
    const can = useCan();
    const editable = invoice.editable && can('einvoices.create');
    const initial = (): FormData => ({
        ...(Object.fromEntries(
            BUYER_FIELDS.map(([key]) => [key, invoice[key] ?? '']),
        ) as Record<BuyerKey, string>),
        payment_method: invoice.payment_method,
        note: invoice.note ?? '',
        items: invoice.items.map((item) => ({
            variant_id: item.variant_id,
            code: item.code,
            name: item.name,
            unit: item.unit,
            quantity: item.quantity,
            price: item.price,
            discount: item.discount,
            tax_percent: item.tax_percent,
            is_promotion: item.is_promotion,
        })),
        issue: false,
    });
    const form = useForm<FormData>(initial());
    const errors = form.errors as Record<string, string | undefined>;
    const [adjusting, setAdjusting] = useState(false);
    const [adjustType, setAdjustType] = useState<string>('increase');
    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');

    const lines = editable ? form.data.items : invoice.items;
    const totals = lines.reduce(
        (sum, line) => {
            const value = amounts(line, priceIncludesTax);

            return {
                subtotal: sum.subtotal + line.quantity * line.price,
                discount:
                    sum.discount +
                    Math.min(line.discount, line.quantity * line.price),
                before: sum.before + value.before,
                tax: sum.tax + value.tax,
                total: sum.total + value.after,
            };
        },
        { subtotal: 0, discount: 0, before: 0, tax: 0, total: 0 },
    );

    const save = (issue: boolean) => {
        form.transform((data) => ({ ...data, issue }));
        form.put(`/e-invoices/${invoice.id}`, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    const saveRef = useRef<() => void>(() => undefined);
    saveRef.current = () => {
        if (editable && !form.processing) {
            save(false);
        }
    };

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                event.key === 'F1' ||
                ((event.ctrlKey || event.metaKey) &&
                    event.key.toLowerCase() === 's')
            ) {
                event.preventDefault();
                saveRef.current();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const setLine = (index: number, patch: Partial<Line>) =>
        form.setData(
            'items',
            form.data.items.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );

    const title =
        invoice.kind === 'adjustment'
            ? 'Hóa đơn điều chỉnh'
            : 'Hóa đơn giá trị gia tăng';

    return (
        <>
            <Head title={`Hóa đơn ${invoice.code}`} />
            <DocumentView
                title={invoice.code}
                badges={
                    <>
                        <StatusPill
                            statuses={einvoiceStatuses}
                            value={invoice.status}
                        />
                        {invoice.kind === 'adjustment' && (
                            <StatusPill
                                statuses={{
                                    adjustment: [
                                        adjustmentLabels[
                                            invoice.adjustment_type ?? 'info'
                                        ],
                                        'info',
                                    ],
                                }}
                                value="adjustment"
                            />
                        )}
                    </>
                }
                meta={`${formatDateTime(invoice.created_at)} · ${invoice.branch}${invoice.creator ? ` · ${invoice.creator}` : ''}`}
                backHref="/e-invoices"
                backLabel="Quay lại danh sách hóa đơn"
                actions={
                    <>
                        {canPdf && invoice.status === 'issued' && (
                            <Button variant="outline" asChild>
                                <a href={`/e-invoices/${invoice.id}/pdf`}>
                                    <Download /> Tải hóa đơn
                                </a>
                            </Button>
                        )}
                        {invoice.status === 'issued' &&
                            invoice.kind === 'original' &&
                            can('einvoices.create') && (
                                <Button
                                    variant="outline"
                                    onClick={() => setAdjusting(true)}
                                >
                                    Lập hóa đơn điều chỉnh
                                </Button>
                            )}
                        {invoice.status !== 'cancelled' &&
                            can('einvoices.cancel') && (
                                <Button
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                    onClick={() => setCancelling(true)}
                                >
                                    Hủy hóa đơn
                                </Button>
                            )}
                        {editable && (
                            <Button
                                variant="outline"
                                disabled={form.processing}
                                onClick={() => save(false)}
                                title="Lưu (F1 hoặc Ctrl+S)"
                            >
                                Lưu
                            </Button>
                        )}
                        {editable && can('einvoices.approve') && (
                            <ConfirmAction
                                onConfirm={() => save(true)}
                                title="Phát hành hóa đơn?"
                                description={
                                    connected
                                        ? 'Hóa đơn được ký số và gửi lên cơ quan thuế qua nhà cung cấp. Sau khi phát hành không sửa được, chỉ hủy hoặc lập hóa đơn điều chỉnh.'
                                        : 'Chưa kết nối nhà cung cấp hóa đơn điện tử, hãy kết nối ở Cấu hình → Hóa đơn điện tử trước.'
                                }
                                confirmLabel="Phát hành"
                                trigger={
                                    <Button disabled={form.processing}>
                                        Phát hành
                                    </Button>
                                }
                            />
                        )}
                    </>
                }
                aside={
                    <>
                        <Card className="gap-0 py-0">
                            <CardHeader className="border-b px-5 py-4">
                                <CardTitle>Thông tin hóa đơn</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2 px-5 py-4 text-sm">
                                <Row label="Đơn vị phát hành">
                                    {invoice.provider ?? 'Chưa kết nối'}
                                </Row>
                                <Row label="Mẫu số">
                                    {invoice.template_code ?? '—'}
                                </Row>
                                <Row label="Ký hiệu">
                                    {invoice.series ?? '—'}
                                </Row>
                                <Row label="Số hóa đơn">
                                    {invoice.invoice_no ?? '—'}
                                </Row>
                                <Row label="Mã tra cứu">
                                    {invoice.lookup_code ?? '—'}
                                </Row>
                                <Row label="Ngày hóa đơn">
                                    {invoice.issued_at
                                        ? formatDateTime(invoice.issued_at)
                                        : '—'}
                                </Row>
                                <Row label="Đơn hàng">
                                    {invoice.order ? (
                                        <Link
                                            href={`/orders/${invoice.order.id}`}
                                            className="text-primary hover:underline"
                                        >
                                            {invoice.order.code}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </Row>
                                {invoice.original && (
                                    <Row label="Điều chỉnh cho">
                                        <Link
                                            href={`/e-invoices/${invoice.original.id}`}
                                            className="text-primary hover:underline"
                                        >
                                            {invoice.original.code}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {invoice.original.number}
                                        </div>
                                    </Row>
                                )}
                                {invoice.cancelled_at && (
                                    <Row label="Ngày hủy">
                                        {formatDateTime(invoice.cancelled_at)}
                                        {invoice.cancel_reason && (
                                            <div className="text-xs text-muted-foreground">
                                                {invoice.cancel_reason}
                                            </div>
                                        )}
                                    </Row>
                                )}
                            </CardContent>
                        </Card>
                        {invoice.adjustments.length > 0 && (
                            <Card className="gap-0 py-0">
                                <CardHeader className="border-b px-5 py-4">
                                    <CardTitle>Hóa đơn điều chỉnh</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-3 px-5 py-4 text-sm">
                                    {invoice.adjustments.map((adjustment) => (
                                        <div
                                            key={adjustment.id}
                                            className="flex items-start justify-between gap-2"
                                        >
                                            <div>
                                                <Link
                                                    href={`/e-invoices/${adjustment.id}`}
                                                    className="text-primary hover:underline"
                                                >
                                                    {adjustment.code}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {adjustment.type}
                                                    {adjustment.invoice_no
                                                        ? ` · Số ${adjustment.invoice_no}`
                                                        : ''}
                                                </div>
                                            </div>
                                            <StatusPill
                                                statuses={einvoiceStatuses}
                                                value={adjustment.status}
                                            />
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}
                    </>
                }
            >
                {invoice.status === 'failed' && invoice.error && (
                    <div className="rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        Phát hành lỗi: {invoice.error}. Sửa thông tin rồi bấm
                        Phát hành lại.
                    </div>
                )}
                {invoice.kind === 'adjustment' && invoice.editable && (
                    <div className="rounded-md border border-primary/30 bg-accent px-4 py-3 text-sm">
                        {invoice.adjustment_type === 'info'
                            ? 'Điều chỉnh thông tin: sửa thông tin người mua cho đúng, không cần dòng hàng hóa.'
                            : 'Chỉ giữ các dòng cần điều chỉnh và nhập phần chênh lệch (số lượng, đơn giá, chiết khấu) so với hóa đơn gốc.'}
                    </div>
                )}

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle className="flex flex-wrap items-baseline justify-between gap-2">
                            <span>{title}</span>
                            {invoice.seller_tax_code && (
                                <span className="text-sm font-normal text-muted-foreground">
                                    MST người bán {invoice.seller_tax_code}
                                </span>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 px-5 py-4 sm:grid-cols-2">
                        {BUYER_FIELDS.map(([key, label]) =>
                            editable ? (
                                <Field
                                    key={key}
                                    label={label}
                                    error={errors[key]}
                                    className={
                                        key === 'buyer_address' ||
                                        key === 'buyer_company'
                                            ? 'sm:col-span-2'
                                            : undefined
                                    }
                                >
                                    <Input
                                        value={form.data[key]}
                                        onChange={(event) =>
                                            form.setData(
                                                key,
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            ) : (
                                <Row key={key} label={label}>
                                    {invoice[key] ?? '—'}
                                </Row>
                            ),
                        )}
                        {editable ? (
                            <Field
                                label="Hình thức thanh toán"
                                error={errors.payment_method}
                            >
                                <OptionSelect
                                    value={form.data.payment_method}
                                    onChange={(value) =>
                                        form.setData(
                                            'payment_method',
                                            value ?? 'TM/CK',
                                        )
                                    }
                                    options={paymentMethods.map((method) => ({
                                        value: method,
                                        label: method,
                                    }))}
                                />
                            </Field>
                        ) : (
                            <Row label="Hình thức thanh toán">
                                {invoice.payment_method}
                            </Row>
                        )}
                        {editable ? (
                            <Field label="Ghi chú" error={errors.note}>
                                <Input
                                    value={form.data.note}
                                    onChange={(event) =>
                                        form.setData('note', event.target.value)
                                    }
                                />
                            </Field>
                        ) : (
                            invoice.note && (
                                <Row label="Ghi chú">{invoice.note}</Row>
                            )
                        )}
                    </CardContent>
                </Card>

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle>Hàng hóa, dịch vụ</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto px-0 py-0">
                        <table className="w-full min-w-[680px] text-sm">
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="w-10 px-3 py-2 font-medium">
                                        STT
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        {editable
                                            ? 'ĐVT'
                                            : 'Tên hàng hóa, dịch vụ'}
                                    </th>
                                    {!editable && (
                                        <th className="w-16 px-3 py-2 font-medium">
                                            ĐVT
                                        </th>
                                    )}
                                    <th className="w-24 px-3 py-2 text-right font-medium">
                                        Số lượng
                                    </th>
                                    <th className="w-32 px-3 py-2 text-right font-medium">
                                        Đơn giá
                                    </th>
                                    <th className="w-28 px-3 py-2 text-right font-medium">
                                        Chiết khấu
                                    </th>
                                    <th className="w-24 px-3 py-2 font-medium">
                                        Thuế suất
                                    </th>
                                    <th className="w-28 px-3 py-2 text-right font-medium">
                                        Thành tiền
                                    </th>
                                    {editable && <th className="w-10" />}
                                </tr>
                            </thead>
                            {lines.map((line, index) => {
                                const value = amounts(line, priceIncludesTax);
                                const total = (
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {formatNumber(value.before)}
                                        {value.tax > 0 && (
                                            <div className="text-xs text-muted-foreground">
                                                Thuế {formatNumber(value.tax)}
                                            </div>
                                        )}
                                    </td>
                                );

                                if (!editable) {
                                    return (
                                        <tbody key={index} className="border-t">
                                            <tr className="align-top">
                                                <td className="px-3 py-2 text-muted-foreground">
                                                    {index + 1}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div>{line.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {line.code}
                                                        {line.is_promotion
                                                            ? ' · Hàng khuyến mại'
                                                            : ''}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {line.unit}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(
                                                        line.quantity,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(line.price)}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(
                                                        line.discount,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {
                                                        taxRates.find(
                                                            (rate) =>
                                                                rate.value ===
                                                                String(
                                                                    line.tax_percent,
                                                                ),
                                                        )?.label
                                                    }
                                                </td>
                                                {total}
                                            </tr>
                                        </tbody>
                                    );
                                }

                                return (
                                    <tbody key={index} className="border-t">
                                        <tr>
                                            <td className="px-3 pt-2 text-muted-foreground">
                                                {index + 1}
                                            </td>
                                            <td
                                                colSpan={7}
                                                className="px-3 pt-2"
                                            >
                                                <Input
                                                    value={line.name}
                                                    placeholder="Tên hàng hóa, dịch vụ"
                                                    onChange={(event) =>
                                                        setLine(index, {
                                                            name: event.target
                                                                .value,
                                                        })
                                                    }
                                                    aria-invalid={Boolean(
                                                        errors[
                                                            `items.${index}.name`
                                                        ],
                                                    )}
                                                />
                                            </td>
                                        </tr>
                                        <tr className="align-top">
                                            <td className="px-3 py-2" />
                                            <td className="px-3 py-2">
                                                <Input
                                                    value={line.unit ?? ''}
                                                    onChange={(event) =>
                                                        setLine(index, {
                                                            unit: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                                {line.code && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {line.code}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <MoneyInput
                                                    value={line.quantity}
                                                    onValueChange={(next) =>
                                                        setLine(index, {
                                                            quantity: next ?? 0,
                                                        })
                                                    }
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <MoneyInput
                                                    value={line.price}
                                                    onValueChange={(next) =>
                                                        setLine(index, {
                                                            price: next ?? 0,
                                                        })
                                                    }
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <MoneyInput
                                                    value={line.discount}
                                                    onValueChange={(next) =>
                                                        setLine(index, {
                                                            discount: next ?? 0,
                                                        })
                                                    }
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <OptionSelect
                                                    value={String(
                                                        line.tax_percent,
                                                    )}
                                                    onChange={(next) =>
                                                        setLine(index, {
                                                            tax_percent: Number(
                                                                next ?? 10,
                                                            ),
                                                        })
                                                    }
                                                    options={taxRates}
                                                />
                                            </td>
                                            {total}
                                            <td className="px-1 py-2">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Xóa dòng"
                                                    onClick={() =>
                                                        form.setData(
                                                            'items',
                                                            form.data.items.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    </tbody>
                                );
                            })}
                            {lines.length === 0 && (
                                <tbody>
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            Không có dòng hàng hóa.
                                        </td>
                                    </tr>
                                </tbody>
                            )}
                        </table>
                        {errors.items && (
                            <p className="px-5 pt-2 text-sm text-destructive">
                                {errors.items}
                            </p>
                        )}
                        {editable && (
                            <div className="border-t px-5 py-3">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        form.setData('items', [
                                            ...form.data.items,
                                            {
                                                variant_id: null,
                                                code: null,
                                                name: '',
                                                unit: null,
                                                quantity: 1,
                                                price: 0,
                                                discount: 0,
                                                tax_percent:
                                                    form.data.items[0]
                                                        ?.tax_percent ?? 10,
                                                is_promotion: false,
                                            },
                                        ])
                                    }
                                >
                                    <Plus /> Thêm dòng
                                </Button>
                            </div>
                        )}
                        <div className="ml-auto grid max-w-sm gap-2 px-5 py-4 text-sm">
                            <Row label="Tổng tiền hàng">
                                {formatNumber(totals.subtotal)}
                            </Row>
                            <Row label="Chiết khấu">
                                {formatNumber(totals.discount)}
                            </Row>
                            <Row label="Tiền trước thuế">
                                {formatNumber(totals.before)}
                            </Row>
                            <Row label="Tiền thuế GTGT">
                                {formatNumber(totals.tax)}
                            </Row>
                            <Row
                                label={
                                    <span className="font-medium text-foreground">
                                        Tổng tiền thanh toán
                                    </span>
                                }
                            >
                                <span className="font-medium">
                                    {formatNumber(totals.total)}
                                </span>
                            </Row>
                            {!editable && (
                                <p className="text-right text-xs text-muted-foreground italic">
                                    {invoice.total_words}
                                </p>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </DocumentView>

            <RecordDialog
                open={adjusting}
                onOpenChange={setAdjusting}
                title="Lập hóa đơn điều chỉnh"
                description="Tạo hóa đơn điều chỉnh nháp cho hóa đơn này. Hóa đơn gốc giữ nguyên."
                processing={false}
                onSubmit={() =>
                    router.post(
                        `/e-invoices/${invoice.id}/adjust`,
                        { adjustment_type: adjustType },
                        { onSuccess: () => setAdjusting(false) },
                    )
                }
            >
                <Field label="Loại điều chỉnh">
                    <OptionSelect
                        value={adjustType}
                        onChange={(value) => setAdjustType(value ?? 'increase')}
                        options={adjustmentTypes}
                    />
                </Field>
            </RecordDialog>

            <RecordDialog
                open={cancelling}
                onOpenChange={setCancelling}
                title="Hủy hóa đơn?"
                description={
                    invoice.status === 'issued'
                        ? 'Hóa đơn được hủy trên hệ thống của nhà cung cấp. Thao tác này không hoàn tác được.'
                        : 'Hóa đơn nháp được hủy, đơn hàng có thể tạo hóa đơn mới.'
                }
                processing={false}
                onSubmit={() =>
                    router.post(
                        `/e-invoices/${invoice.id}/cancel`,
                        { reason },
                        {
                            preserveScroll: true,
                            onSuccess: () => setCancelling(false),
                        },
                    )
                }
            >
                <Field label="Lý do hủy" error={errors.reason}>
                    <Input
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        autoFocus
                    />
                </Field>
            </RecordDialog>
        </>
    );
}

function Row({ label, children }: { label: ReactNode; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{children}</span>
        </div>
    );
}

EinvoiceShow.layout = {
    breadcrumbs: [
        { title: 'Đơn hàng', href: '/orders' },
        { title: 'Hóa đơn điện tử', href: '/e-invoices' },
        { title: 'Chi tiết hóa đơn', href: '' },
    ],
};
