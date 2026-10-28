import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { FormSection } from '@/components/app/document-form';
import { DocumentLines, SummaryRow } from '@/components/app/document-lines';
import type { LineColumn, LineProduct } from '@/components/app/document-lines';
import { DocumentView } from '@/components/app/document-view';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { Pill } from '@/components/app/status-badge';
import type { SupplierOption } from '@/components/app/supplier-picker';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { paymentStatuses, receiptStatuses } from '../../components/statuses';

type Item = LineProduct & {
    id: number;
    quantity: number;
    returned_quantity: number;
    price: number;
    discount: number;
    total: number;
    unit_cost: number | null;
    return_price: number;
};

type Payment = {
    id: number;
    amount: number;
    method: string | null;
    note: string | null;
    creator: string | null;
    created_at: string | null;
};

type Return = {
    id: number;
    code: string;
    total: number;
    note: string | null;
    creator: string | null;
    created_at: string | null;
    items: string[];
};

type Receipt = {
    id: number;
    code: string;
    status: string;
    payment_status: string | null;
    supplier: SupplierOption;
    branch: string;
    purchase_order: { id: number; code: string } | null;
    reference: string | null;
    note: string | null;
    subtotal: number;
    discount: number;
    costs: { name: string; amount: number }[];
    cost_total: number;
    total: number;
    paid: number;
    returned_total: number;
    remaining: number;
    creator: string | null;
    receiver: string | null;
    created_at: string | null;
    received_at: string | null;
    items: Item[];
    payments: Payment[];
    returns: Return[];
};

type Method = { value: string; label: string };

function PayDialog({
    receipt,
    methods,
}: {
    receipt: Receipt;
    methods: Method[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        amount: receipt.remaining,
        method: methods[0]?.value ?? 'cash',
        note: '',
    });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData('amount', receipt.remaining);
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>Thanh toán</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Thanh toán cho nhà cung cấp</DialogTitle>
                <DialogDescription>
                    Còn phải trả {formatNumber(receipt.remaining)} đ cho{' '}
                    {receipt.supplier.name}.
                </DialogDescription>
                <form
                    id="pay-form"
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/purchase-receipts/${receipt.id}/pay`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <Field label="Số tiền" error={form.errors.amount}>
                        <MoneyInput
                            autoFocus
                            value={form.data.amount}
                            onValueChange={(value) =>
                                form.setData('amount', value ?? 0)
                            }
                        />
                    </Field>
                    <Field
                        label="Hình thức thanh toán"
                        error={form.errors.method}
                    >
                        <Select
                            value={form.data.method}
                            onValueChange={(value) =>
                                form.setData('method', value)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {methods.map((method) => (
                                    <SelectItem
                                        key={method.value}
                                        value={method.value}
                                    >
                                        {method.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label="Ghi chú" error={form.errors.note}>
                        <Input
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </Field>
                </form>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Thoát</Button>
                    </DialogClose>
                    <Button form="pay-form" disabled={form.processing}>
                        Xác nhận thanh toán
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ReturnDialog({ receipt }: { receipt: Receipt }) {
    const [open, setOpen] = useState(false);
    const left = (item: Item) => item.quantity - item.returned_quantity;
    const form = useForm({
        items: {} as Record<number, number>,
        note: '',
    });
    const total = receipt.items.reduce(
        (sum, item) =>
            sum + (form.data.items[item.id] ?? 0) * item.return_price,
        0,
    );

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">Trả hàng</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <DialogTitle>Trả hàng cho nhà cung cấp</DialogTitle>
                <DialogDescription>
                    Hàng trả được xuất khỏi kho {receipt.branch}; giá trị trả
                    được trừ vào công nợ với {receipt.supplier.name}.
                </DialogDescription>
                <form
                    id="return-form"
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/purchase-receipts/${receipt.id}/return`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <div className="max-h-80 overflow-y-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Sản phẩm</TableHead>
                                    <TableHead className="text-right">
                                        Có thể trả
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Giá trả
                                    </TableHead>
                                    <TableHead className="w-28">
                                        Số lượng trả
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {receipt.items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <span className="block">
                                                {item.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {item.sku}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(left(item))}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(item.return_price)}
                                        </TableCell>
                                        <TableCell>
                                            <MoneyInput
                                                aria-label={`Số lượng trả ${item.name}`}
                                                disabled={left(item) <= 0}
                                                value={
                                                    form.data.items[item.id] ??
                                                    0
                                                }
                                                onValueChange={(value) =>
                                                    form.setData('items', {
                                                        ...form.data.items,
                                                        [item.id]: Math.min(
                                                            value ?? 0,
                                                            left(item),
                                                        ),
                                                    })
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    {form.errors.items && (
                        <p className="text-sm text-destructive">
                            {form.errors.items}
                        </p>
                    )}
                    <Field label="Lý do trả hàng" error={form.errors.note}>
                        <Input
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </Field>
                    <SummaryRow
                        strong
                        label="Giá trị hàng trả"
                        value={formatNumber(total)}
                    />
                </form>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Thoát</Button>
                    </DialogClose>
                    <Button
                        form="return-form"
                        disabled={form.processing || total === 0}
                    >
                        Trả hàng
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Purchase receipt detail like Sapo, with receive / pay / return / cancel. */
export default function PurchaseReceiptShow({
    receipt,
    paymentMethods,
}: {
    receipt: Receipt;
    paymentMethods: Method[];
}) {
    const can = useCan();
    const draft = receipt.status === 'draft';
    const received = receipt.status === 'received';
    const [statusLabel, statusTone] = receiptStatuses[receipt.status] ?? [
        receipt.status,
    ];
    const payment = receipt.payment_status
        ? paymentStatuses[receipt.payment_status]
        : null;
    const showCost = receipt.items.some((item) => item.unit_cost !== null);
    const returnable = receipt.items.some(
        (item) => item.quantity > item.returned_quantity,
    );

    const columns: LineColumn<Item>[] = [
        {
            key: 'quantity',
            header: 'Số lượng',
            className: 'text-right tabular-nums',
            cell: (item) => (
                <>
                    {formatNumber(item.quantity)}
                    {item.returned_quantity > 0 && (
                        <span className="block text-xs text-destructive">
                            Đã trả {formatNumber(item.returned_quantity)}
                        </span>
                    )}
                </>
            ),
        },
        {
            key: 'price',
            header: 'Đơn giá',
            className: 'text-right tabular-nums',
            cell: (item) => formatNumber(item.price),
        },
        {
            key: 'discount',
            header: 'Chiết khấu',
            className: 'text-right tabular-nums',
            cell: (item) => formatNumber(item.discount),
        },
        ...(showCost
            ? [
                  {
                      key: 'unit_cost',
                      header: 'Giá vốn nhập',
                      className:
                          'text-right tabular-nums text-muted-foreground',
                      cell: (item: Item) =>
                          item.unit_cost === null
                              ? ''
                              : formatNumber(item.unit_cost),
                  },
              ]
            : []),
        {
            key: 'total',
            header: 'Thành tiền',
            className: 'text-right tabular-nums',
            cell: (item) => formatNumber(item.total),
        },
    ];

    return (
        <>
            <Head title={`Phiếu nhập ${receipt.code}`} />
            <DocumentView
                title={receipt.code}
                backHref="/purchase-receipts"
                backLabel="Quay lại danh sách phiếu nhập"
                badges={
                    <>
                        <Pill tone={statusTone}>{statusLabel}</Pill>
                        {payment && <Pill tone={payment[1]}>{payment[0]}</Pill>}
                    </>
                }
                meta={`Tạo lúc ${formatDateTime(receipt.created_at)}${receipt.creator ? ` bởi ${receipt.creator}` : ''}`}
                actions={
                    <>
                        {receipt.status !== 'cancelled' &&
                            can('purchases.cancel') && (
                                <ConfirmAction
                                    url={`/purchase-receipts/${receipt.id}/cancel`}
                                    title={`Hủy phiếu nhập ${receipt.code}?`}
                                    description={
                                        received
                                            ? 'Hàng đã nhập sẽ được xuất trả khỏi kho và công nợ nhà cung cấp được ghi giảm. Phiếu đã thanh toán hoặc đã trả hàng thì không hủy được.'
                                            : 'Phiếu nháp sẽ chuyển sang Đã hủy.'
                                    }
                                    confirmLabel="Hủy phiếu"
                                    destructive
                                    trigger={
                                        <Button
                                            variant="outline"
                                            className="text-destructive hover:text-destructive"
                                        >
                                            Hủy phiếu
                                        </Button>
                                    }
                                />
                            )}
                        {draft && can('purchases.update') && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/purchase-receipts/${receipt.id}/edit`}
                                >
                                    Sửa phiếu
                                </Link>
                            </Button>
                        )}
                        {received && returnable && can('purchases.approve') && (
                            <ReturnDialog receipt={receipt} />
                        )}
                        {received &&
                            receipt.remaining > 0 &&
                            can('purchases.pay') && (
                                <PayDialog
                                    receipt={receipt}
                                    methods={paymentMethods}
                                />
                            )}
                        {draft && can('purchases.approve') && (
                            <Button
                                onClick={() =>
                                    router.post(
                                        `/purchase-receipts/${receipt.id}/receive`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Nhập kho
                            </Button>
                        )}
                    </>
                }
                aside={
                    <>
                        <FormSection title="Thông tin phiếu" className="gap-2">
                            <SummaryRow
                                label="Chi nhánh"
                                value={receipt.branch}
                            />
                            {receipt.purchase_order && (
                                <SummaryRow label="Đơn đặt hàng">
                                    <Link
                                        href={`/purchase-orders/${receipt.purchase_order.id}`}
                                        className="text-primary hover:underline"
                                    >
                                        {receipt.purchase_order.code}
                                    </Link>
                                </SummaryRow>
                            )}
                            <SummaryRow
                                label="Mã tham chiếu"
                                value={receipt.reference ?? '—'}
                            />
                            {receipt.received_at && (
                                <SummaryRow
                                    label="Ngày nhập kho"
                                    value={formatDateTime(receipt.received_at)}
                                />
                            )}
                            {receipt.receiver && (
                                <SummaryRow
                                    label="Người nhập kho"
                                    value={receipt.receiver}
                                />
                            )}
                        </FormSection>
                        <FormSection title="Ghi chú">
                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                {receipt.note || 'Chưa có ghi chú.'}
                            </p>
                        </FormSection>
                    </>
                }
            >
                <FormSection title="Nhà cung cấp" className="gap-1 text-sm">
                    <Link
                        href={`/suppliers/${receipt.supplier.id}`}
                        className="font-medium text-primary hover:underline"
                    >
                        {receipt.supplier.name}
                    </Link>
                    <span className="text-muted-foreground">
                        {receipt.supplier.code}
                        {receipt.supplier.phone
                            ? ` · ${receipt.supplier.phone}`
                            : ''}
                    </span>
                    <span>
                        Công nợ hiện tại:{' '}
                        <span
                            className={cn(
                                'font-medium tabular-nums',
                                receipt.supplier.debt > 0 && 'text-destructive',
                            )}
                        >
                            {formatNumber(receipt.supplier.debt)}
                        </span>
                    </span>
                </FormSection>
                <DocumentLines
                    readOnly
                    lines={receipt.items}
                    columns={columns}
                    footer={
                        <div className="grid gap-2 border-t px-5 py-4 md:ml-auto md:w-96">
                            <SummaryRow
                                label="Tổng tiền hàng"
                                value={formatNumber(receipt.subtotal)}
                            />
                            <SummaryRow
                                label="Chiết khấu đơn"
                                value={formatNumber(receipt.discount)}
                            />
                            {receipt.costs.map((cost, index) => (
                                <SummaryRow
                                    key={index}
                                    label={`Chi phí: ${cost.name}`}
                                    value={formatNumber(cost.amount)}
                                />
                            ))}
                            <SummaryRow
                                strong
                                label="Tiền cần trả"
                                value={formatNumber(receipt.total)}
                            />
                            {receipt.returned_total > 0 && (
                                <SummaryRow
                                    label="Hàng đã trả"
                                    value={`−${formatNumber(receipt.returned_total)}`}
                                />
                            )}
                            <SummaryRow
                                label="Đã thanh toán"
                                value={formatNumber(receipt.paid)}
                            />
                            <SummaryRow
                                strong
                                label="Còn phải trả"
                                value={formatNumber(
                                    receipt.status === 'cancelled'
                                        ? 0
                                        : receipt.remaining,
                                )}
                            />
                        </div>
                    }
                />
                {receipt.payments.length > 0 && (
                    <Card className="gap-0 overflow-hidden py-0">
                        <h3 className="border-b px-5 py-4 font-medium">
                            Lịch sử thanh toán
                        </h3>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-5">
                                        Thời gian
                                    </TableHead>
                                    <TableHead>Hình thức</TableHead>
                                    <TableHead>Người thực hiện</TableHead>
                                    <TableHead>Ghi chú</TableHead>
                                    <TableHead className="pr-5 text-right">
                                        Số tiền
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {receipt.payments.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="pl-5">
                                            {formatDateTime(line.created_at)}
                                        </TableCell>
                                        <TableCell>{line.method}</TableCell>
                                        <TableCell>{line.creator}</TableCell>
                                        <TableCell>{line.note}</TableCell>
                                        <TableCell className="pr-5 text-right tabular-nums">
                                            {formatNumber(line.amount)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
                {receipt.returns.length > 0 && (
                    <Card className="gap-0 overflow-hidden py-0">
                        <h3 className="border-b px-5 py-4 font-medium">
                            Phiếu trả hàng
                        </h3>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-5">
                                        Mã phiếu
                                    </TableHead>
                                    <TableHead>Thời gian</TableHead>
                                    <TableHead>Sản phẩm</TableHead>
                                    <TableHead className="pr-5 text-right">
                                        Giá trị
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {receipt.returns.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="pl-5">
                                            {line.code}
                                        </TableCell>
                                        <TableCell>
                                            {formatDateTime(line.created_at)}
                                        </TableCell>
                                        <TableCell className="text-sm whitespace-normal">
                                            {line.items.join(', ')}
                                            {line.note && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {line.note}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="pr-5 text-right tabular-nums">
                                            {formatNumber(line.total)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </DocumentView>
        </>
    );
}

PurchaseReceiptShow.layout = {
    breadcrumbs: [
        { title: 'Nhập hàng', href: '/purchase-receipts' },
        { title: 'Chi tiết phiếu nhập', href: '#' },
    ],
};
