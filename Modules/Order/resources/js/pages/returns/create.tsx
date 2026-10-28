import { Head, Link, router, useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { PageErrors } from '@/components/app/page-errors';
import { ProductThumb } from '@/components/app/product-thumb';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PaymentMethodOption } from '../../components/payment-dialog';

type Item = {
    id: number;
    variant_id: number | null;
    name: string;
    sku: string;
    unit: string | null;
    image_url: string | null;
    quantity: number;
    returned_quantity: number;
    price: number;
    refund_price: number;
};

type Order = {
    id: number;
    code: string;
    status: string;
    exported: boolean;
    customer: {
        id: number;
        name: string;
        phone: string | null;
        code: string;
    } | null;
    subtotal: number;
    discount: number;
    total: number;
    paid: number;
    returned_total: number;
    items: Item[];
};

/** Tạo đơn trả hàng: pick quantities to take back, then refund. */
export default function ReturnCreate({
    order,
    searched,
    reasons,
    requireReason,
    paymentMethods,
    defaultMethod,
    canRefund,
}: {
    order: Order | null;
    searched: string | null;
    reasons: { id: number; name: string }[];
    requireReason: boolean;
    paymentMethods: PaymentMethodOption[];
    defaultMethod: number | string | null;
    canRefund: boolean;
}) {
    if (!order || !order.exported) {
        return <PickOrder order={order} searched={searched} />;
    }

    return (
        <ReturnForm
            order={order}
            reasons={reasons}
            requireReason={requireReason}
            paymentMethods={paymentMethods}
            defaultMethod={defaultMethod}
            canRefund={canRefund}
        />
    );
}

function PickOrder({
    order,
    searched,
}: {
    order: Order | null;
    searched: string | null;
}) {
    const [code, setCode] = useState(searched ?? '');

    return (
        <>
            <Head title="Tạo đơn trả hàng" />
            <div className="mx-auto w-full max-w-xl space-y-4 px-4 pt-10 pb-10">
                <h2 className="text-xl font-medium">Tạo đơn trả hàng</h2>
                <Card className="gap-3 px-5 py-5">
                    <p className="text-sm text-muted-foreground">
                        Nhập mã đơn hàng đã xuất kho để chọn sản phẩm khách trả
                        lại.
                    </p>
                    <form
                        className="flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get('/returns/create', { code });
                        }}
                    >
                        <div className="relative flex-1">
                            <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                            <Input
                                autoFocus
                                className="pl-8"
                                value={code}
                                placeholder="VD: SON00012"
                                onChange={(event) =>
                                    setCode(event.target.value)
                                }
                            />
                        </div>
                        <Button disabled={code.trim() === ''}>Tìm đơn</Button>
                    </form>
                    {searched && !order && (
                        <p className="text-sm text-destructive">
                            Không tìm thấy đơn hàng {searched}.
                        </p>
                    )}
                    {order && !order.exported && (
                        <p className="text-sm text-destructive">
                            Đơn{' '}
                            <Link
                                href={`/orders/${order.id}`}
                                className="underline"
                            >
                                {order.code}
                            </Link>{' '}
                            chưa xuất kho nên chưa trả hàng được. Hãy sửa hoặc
                            hủy đơn.
                        </p>
                    )}
                </Card>
            </div>
        </>
    );
}

function ReturnForm({
    order,
    reasons,
    requireReason,
    paymentMethods,
    defaultMethod,
    canRefund,
}: {
    order: Order;
    reasons: { id: number; name: string }[];
    requireReason: boolean;
    paymentMethods: PaymentMethodOption[];
    defaultMethod: number | string | null;
    canRefund: boolean;
}) {
    const initialMethod =
        paymentMethods.find(
            (method) => String(method.id) === String(defaultMethod ?? ''),
        )?.id ??
        paymentMethods[0]?.id ??
        null;
    const form = useForm({
        order_id: order.id,
        items: Object.fromEntries(
            order.items.map((item) => [item.id, 0]),
        ) as Record<string, number>,
        reason_id: null as number | null,
        note: '',
        refund_method_id: initialMethod as number | null,
        refund_amount: 0,
    });
    const [refunding, setRefunding] = useState(canRefund);
    const [touched, setTouched] = useState(false);
    const errors = form.errors as Record<string, string | undefined>;

    const value = useMemo(
        () =>
            order.items.reduce(
                (sum, item) =>
                    sum + (form.data.items[item.id] ?? 0) * item.refund_price,
                0,
            ),
        [form.data.items, order.items],
    );
    const quantity = Object.values(form.data.items).reduce(
        (sum, item) => sum + item,
        0,
    );
    // Same as ReturnService::suggestedRefund(): what was paid beyond what is still due.
    const suggested = Math.max(
        0,
        Math.min(
            value,
            order.paid - (order.total - order.returned_total - value),
        ),
    );

    useEffect(() => {
        if (!touched) {
            form.setData('refund_amount', suggested);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [suggested, touched]);

    const submit = () => {
        form.transform((data) => ({
            ...data,
            refund_amount: refunding ? data.refund_amount : 0,
        }));
        form.post('/returns');
    };

    return (
        <>
            <Head title={`Trả hàng đơn ${order.code}`} />
            <DocumentForm
                title={`Tạo đơn trả hàng cho đơn ${order.code}`}
                backHref={`/orders/${order.id}`}
                backLabel="Quay lại đơn hàng"
                onSubmit={submit}
                processing={form.processing}
                isDirty={quantity > 0}
                submitLabel="Trả hàng"
                aside={
                    <>
                        <FormSection title="Thông tin đơn trả">
                            <div className="text-sm">
                                Khách hàng:{' '}
                                {order.customer ? (
                                    <Link
                                        href={`/customers/${order.customer.id}`}
                                        className="text-primary hover:underline"
                                    >
                                        {order.customer.name}
                                    </Link>
                                ) : (
                                    'Khách lẻ'
                                )}
                            </div>
                            <Field
                                label={
                                    requireReason
                                        ? 'Lý do trả hàng *'
                                        : 'Lý do trả hàng'
                                }
                                error={errors.reason_id}
                            >
                                <OptionSelect
                                    value={
                                        form.data.reason_id
                                            ? String(form.data.reason_id)
                                            : null
                                    }
                                    onChange={(next) =>
                                        form.setData(
                                            'reason_id',
                                            next ? Number(next) : null,
                                        )
                                    }
                                    options={toSelectOptions(reasons)}
                                    emptyLabel={
                                        requireReason ? undefined : 'Không chọn'
                                    }
                                    placeholder="Chọn lý do"
                                />
                            </Field>
                            <Field label="Ghi chú" error={errors.note}>
                                <Textarea
                                    value={form.data.note}
                                    onChange={(event) =>
                                        form.setData('note', event.target.value)
                                    }
                                />
                            </Field>
                        </FormSection>
                    </>
                }
            >
                <PageErrors
                    keys={['items', 'stock', 'status', 'amount', 'refund']}
                />
                <FormSection
                    title="Sản phẩm trả lại"
                    description="Hàng trả được nhập lại kho theo giá vốn lúc bán."
                >
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr className="border-b">
                                    <th className="py-2 font-normal">
                                        Sản phẩm
                                    </th>
                                    <th className="w-36 py-2 text-right font-normal">
                                        Số lượng trả
                                    </th>
                                    <th className="py-2 text-right font-normal">
                                        Giá hoàn
                                    </th>
                                    <th className="py-2 text-right font-normal">
                                        Thành tiền
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.items.map((item) => {
                                    const left =
                                        item.quantity - item.returned_quantity;
                                    const picked =
                                        form.data.items[item.id] ?? 0;

                                    return (
                                        <tr
                                            key={item.id}
                                            className={cn(
                                                'border-b last:border-0',
                                                left === 0 && 'opacity-50',
                                            )}
                                        >
                                            <td className="py-3">
                                                <div className="flex gap-3">
                                                    <ProductThumb
                                                        url={item.image_url}
                                                        className="size-10"
                                                    />
                                                    <div>
                                                        <div>{item.name}</div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {item.sku ||
                                                                'Dịch vụ khác'}{' '}
                                                            · Đã mua{' '}
                                                            {formatNumber(
                                                                item.quantity,
                                                            )}
                                                            {item.returned_quantity >
                                                            0
                                                                ? `, đã trả ${formatNumber(item.returned_quantity)}`
                                                                : ''}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="py-3">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Input
                                                        inputMode="numeric"
                                                        className="h-8 w-20 text-right"
                                                        disabled={left === 0}
                                                        value={picked}
                                                        onFocus={(event) =>
                                                            event.currentTarget.select()
                                                        }
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'items',
                                                                {
                                                                    ...form.data
                                                                        .items,
                                                                    [item.id]:
                                                                        Math.min(
                                                                            left,
                                                                            Number(
                                                                                event.target.value.replace(
                                                                                    /\D/g,
                                                                                    '',
                                                                                ),
                                                                            ) ||
                                                                                0,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    />
                                                    <span className="w-10 text-xs text-muted-foreground">
                                                        / {formatNumber(left)}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="py-3 text-right tabular-nums">
                                                {formatNumber(
                                                    item.refund_price,
                                                )}
                                            </td>
                                            <td className="py-3 text-right tabular-nums">
                                                {formatNumber(
                                                    picked * item.refund_price,
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex justify-between border-t pt-3 text-sm font-semibold">
                        <span>
                            Tổng giá trị hàng trả ({formatNumber(quantity)} sản
                            phẩm)
                        </span>
                        <span className="tabular-nums">
                            {formatNumber(value)}
                        </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Giá hoàn đã trừ chiết khấu của dòng và phần chiết khấu
                        đơn chia theo giá trị dòng.
                    </p>
                </FormSection>
                {canRefund && (
                    <FormSection title="Hoàn tiền">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={refunding}
                                onChange={(event) =>
                                    setRefunding(event.target.checked)
                                }
                            />
                            Hoàn tiền cho khách ngay
                        </label>
                        {refunding && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Hình thức hoàn tiền"
                                    error={errors.refund_method_id}
                                >
                                    <OptionSelect
                                        value={
                                            form.data.refund_method_id
                                                ? String(
                                                      form.data
                                                          .refund_method_id,
                                                  )
                                                : null
                                        }
                                        onChange={(next) =>
                                            form.setData(
                                                'refund_method_id',
                                                next ? Number(next) : null,
                                            )
                                        }
                                        options={toSelectOptions(
                                            paymentMethods,
                                        )}
                                    />
                                </Field>
                                <Field
                                    label="Số tiền hoàn"
                                    error={
                                        errors.refund_amount ?? errors.amount
                                    }
                                >
                                    <MoneyInput
                                        value={form.data.refund_amount}
                                        onValueChange={(next) => {
                                            setTouched(true);
                                            form.setData(
                                                'refund_amount',
                                                next ?? 0,
                                            );
                                        }}
                                    />
                                </Field>
                            </div>
                        )}
                        <p className="text-sm text-muted-foreground">
                            Khách đã trả {formatNumber(order.paid)} đ cho đơn.
                            Gợi ý hoàn {formatNumber(suggested)} đ; phần còn lại
                            được trừ vào công nợ.
                        </p>
                    </FormSection>
                )}
            </DocumentForm>
        </>
    );
}

ReturnCreate.layout = {
    breadcrumbs: [
        { title: 'Khách trả hàng', href: '/returns' },
        { title: 'Tạo đơn trả hàng', href: '#' },
    ],
};
