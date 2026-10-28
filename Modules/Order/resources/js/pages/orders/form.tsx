import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, Plus, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { CustomerPicker } from '@/components/app/customer-picker';
import type { CustomerOption } from '@/components/app/customer-picker';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import type { SelectOption } from '@/components/app/option-select';
import { PageErrors } from '@/components/app/page-errors';
import { PercentInput } from '@/components/app/percent-input';
import { ProductThumb } from '@/components/app/product-thumb';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    OrderProductSearch,
    repriceVariants,
} from '../../components/order-product-search';
import type {
    SaleVariant,
    SearchHandle,
} from '../../components/order-product-search';
import type { PaymentMethodOption } from '../../components/payment-dialog';
import { lineTotal, orderTotals } from '../../components/totals';

type Option = { id: number; name: string };

type Line = {
    key: string;
    variant_id: number | null;
    product_id: number | null;
    name: string;
    sku: string;
    unit: string | null;
    image_url: string | null;
    type: string;
    quantity: number;
    price: number;
    discount: number;
    note: string | null;
    available: number | null;
};

type Draft = {
    id: number | null;
    code: string | null;
    status: string | null;
    paid: number;
    branch_id: number;
    customer: CustomerOption | null;
    source_id: number | null;
    assigned_to: number | null;
    price_policy: string;
    delivery_method: string;
    carrier_name: string | null;
    tracking_code: string | null;
    receiver_name: string | null;
    receiver_phone: string | null;
    shipping_address: string | null;
    shipping_fee: number;
    discount: number;
    discount_bp: number | null;
    note: string | null;
    tags: string | null;
    reference: string | null;
    expected_delivery_at: string | null;
    items: Omit<Line, 'key'>[];
};

type Payment = { payment_method_id: number | null; amount: number };

let keySeed = 0;
const nextKey = () => `line-${++keySeed}`;

const deliveryOptions = [
    { value: 'carrier', label: 'Đẩy qua hãng vận chuyển', soon: true },
    { value: 'external', label: 'Đẩy vận chuyển ngoài' },
    { value: 'pickup', label: 'Nhận tại cửa hàng' },
    { value: 'later', label: 'Giao hàng sau' },
];

/**
 * "Tạo đơn hàng" like Sapo: customer (F4), products (F3, quantity gets the
 * focus), discount (F6), shipping fee (F7), service line (F9), payments
 * pre-filled with what is left, delivery options and a split save button.
 */
export default function OrderForm({
    order,
    presetCustomer = null,
    branches,
    currentBranchId,
    sources,
    staff,
    paymentMethods,
    pricePolicies,
    canEditPrice,
    defaultOnlineMethod,
}: {
    order: Draft | null;
    presetCustomer?: CustomerOption | null;
    branches: Option[];
    currentBranchId: number | null;
    sources: (Option & { is_default: boolean })[];
    staff: Option[];
    paymentMethods: PaymentMethodOption[];
    pricePolicies: SelectOption[];
    canEditPrice: boolean;
    defaultOnlineMethod: number | string | null;
}) {
    const can = useCan();
    const editing = order?.id != null;
    const search = useRef<SearchHandle>(null);
    const shippingInput = useRef<HTMLInputElement>(null);
    const quantityInputs = useRef<Record<string, HTMLInputElement | null>>({});
    const [focusKey, setFocusKey] = useState<string | null>(null);
    const [discountOpen, setDiscountOpen] = useState(false);
    const [serviceOpen, setServiceOpen] = useState(false);
    const [paying, setPaying] = useState(false);
    const [paymentTouched, setPaymentTouched] = useState(false);
    const customer0 = order?.customer ?? presetCustomer;
    const defaultMethod =
        paymentMethods.find(
            (method) => String(method.id) === String(defaultOnlineMethod ?? ''),
        )?.id ??
        paymentMethods[0]?.id ??
        null;

    const form = useForm({
        branch_id: (order?.branch_id ??
            currentBranchId ??
            branches[0]?.id ??
            null) as number | null,
        customer: customer0,
        source_id:
            order?.source_id ??
            sources.find((source) => source.is_default)?.id ??
            null,
        assigned_to: order?.assigned_to ?? null,
        price_policy:
            order?.price_policy ?? customer0?.price_policy ?? 'retail_price',
        delivery_method: order?.delivery_method ?? 'later',
        carrier_name: order?.carrier_name ?? '',
        tracking_code: order?.tracking_code ?? '',
        receiver_name: order?.receiver_name ?? customer0?.name ?? '',
        receiver_phone: order?.receiver_phone ?? customer0?.phone ?? '',
        shipping_address:
            order?.shipping_address ??
            customer0?.addresses[0]?.address ??
            customer0?.address ??
            '',
        shipping_fee: order?.shipping_fee ?? 0,
        discount: order?.discount ?? 0,
        discount_bp:
            order?.discount_bp ??
            (customer0 && customer0.discount_bp > 0
                ? customer0.discount_bp
                : null),
        note: order?.note ?? '',
        tags: order?.tags ?? '',
        reference: order?.reference ?? '',
        expected_delivery_at: order?.expected_delivery_at ?? '',
        items: (order?.items ?? []).map((item) => ({
            ...item,
            key: nextKey(),
        })) as Line[],
        payments: [
            {
                payment_method_id:
                    customer0?.payment_method_id ?? defaultMethod,
                amount: 0,
            },
        ] as Payment[],
        next_step: null as string | null,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const data = form.data;

    const totals = useMemo(
        () =>
            orderTotals(
                data.items,
                data.discount,
                data.discount_bp,
                data.shipping_fee,
            ),
        [data.items, data.discount, data.discount_bp, data.shipping_fee],
    );
    const alreadyPaid = order?.paid ?? 0;
    const remaining = Math.max(0, totals.total - alreadyPaid);
    const paidNow = paying
        ? data.payments.reduce((sum, payment) => sum + payment.amount, 0)
        : 0;

    // Payment starts at what is left to pay and follows the total until the
    // user types another amount (Sapo pre-fills the same way).
    useEffect(() => {
        if (paying && !paymentTouched && data.payments.length === 1) {
            form.setData('payments', [
                { ...data.payments[0], amount: remaining },
            ]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [paying, paymentTouched, remaining]);

    useEffect(() => {
        if (focusKey) {
            const input = quantityInputs.current[focusKey];
            input?.focus();
            input?.select();
            setFocusKey(null);
        }
    }, [focusKey, data.items]);

    const setItems = (items: Line[]) => form.setData('items', items);

    const updateLine = (key: string, patch: Partial<Line>) =>
        setItems(
            data.items.map((line) =>
                line.key === key ? { ...line, ...patch } : line,
            ),
        );

    const addVariant = (variant: SaleVariant) => {
        const existing = data.items.find(
            (line) => line.variant_id === variant.id,
        );

        if (existing) {
            updateLine(existing.key, { quantity: existing.quantity + 1 });
            setFocusKey(existing.key);

            return;
        }

        const key = nextKey();
        setItems([
            ...data.items,
            {
                key,
                variant_id: variant.id,
                product_id: variant.product_id,
                name: variant.name,
                sku: variant.sku,
                unit: variant.unit,
                image_url: variant.image_url,
                type: variant.type,
                quantity: 1,
                price: variant.price,
                discount: 0,
                note: null,
                available: variant.available,
            },
        ]);
        setFocusKey(key);
    };

    const applyPolicy = useCallback(
        (policy: string, items: Line[]) => {
            const ids = items.flatMap((line) =>
                line.variant_id ? [line.variant_id] : [],
            );
            void repriceVariants(ids, policy).then((prices) =>
                form.setData((previous) => ({
                    ...previous,
                    price_policy: policy,
                    items: previous.items.map((line) =>
                        line.variant_id && prices[line.variant_id] !== undefined
                            ? { ...line, price: prices[line.variant_id] }
                            : line,
                    ),
                })),
            );
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const pickCustomer = useCallback(
        (customer: CustomerOption | null) => {
            form.setData((previous) => ({
                ...previous,
                customer,
                receiver_name: customer?.name ?? '',
                receiver_phone: customer?.phone ?? '',
                shipping_address:
                    customer?.addresses[0]?.address ?? customer?.address ?? '',
                discount_bp:
                    customer && customer.discount_bp > 0
                        ? customer.discount_bp
                        : previous.discount_bp,
                payments:
                    customer?.payment_method_id != null
                        ? previous.payments.map((payment, index) =>
                              index === 0
                                  ? {
                                        ...payment,
                                        payment_method_id:
                                            customer.payment_method_id,
                                    }
                                  : payment,
                          )
                        : previous.payments,
            }));

            const policy = customer?.price_policy ?? 'retail_price';

            if (policy !== form.data.price_policy) {
                applyPolicy(policy, form.data.items);
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [applyPolicy, form.data.price_policy, form.data.items],
    );

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F6') {
                event.preventDefault();
                setDiscountOpen(true);
            } else if (event.key === 'F7') {
                event.preventDefault();

                if (data.delivery_method === 'pickup') {
                    form.setData('delivery_method', 'later');
                }

                setTimeout(() => shippingInput.current?.focus(), 0);
            } else if (event.key === 'F9') {
                event.preventDefault();
                setServiceOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.delivery_method]);

    const save = (then: string | null) => {
        form.transform((values) => ({
            branch_id: values.branch_id,
            customer_id: values.customer?.id ?? null,
            source_id: values.source_id,
            assigned_to: values.assigned_to,
            price_policy: values.price_policy,
            delivery_method: values.delivery_method,
            carrier_name:
                values.delivery_method === 'external'
                    ? values.carrier_name
                    : null,
            tracking_code:
                values.delivery_method === 'external'
                    ? values.tracking_code
                    : null,
            receiver_name: values.receiver_name,
            receiver_phone: values.receiver_phone,
            shipping_address:
                values.delivery_method === 'pickup'
                    ? null
                    : values.shipping_address,
            shipping_fee:
                values.delivery_method === 'pickup' ? 0 : values.shipping_fee,
            discount: values.discount,
            discount_bp: values.discount_bp,
            note: values.note,
            tags: values.tags,
            reference: values.reference,
            expected_delivery_at: values.expected_delivery_at,
            items: values.items.map((line) => ({
                variant_id: line.variant_id,
                name: line.name,
                quantity: line.quantity,
                price: line.price,
                discount: line.discount,
                note: line.note,
            })),
            payments:
                !editing && paying
                    ? values.payments.filter(
                          (payment) =>
                              payment.payment_method_id && payment.amount > 0,
                      )
                    : [],
            next_step: then,
        }));

        if (editing && order?.id) {
            form.put(`/orders/${order.id}`, { preserveScroll: true });
        } else {
            form.post('/orders', { preserveScroll: true });
        }
    };

    const canApprove = can('orders.approve');
    const canShip = can('orders.ship');
    const primaryThen = editing ? null : canApprove ? 'approve' : null;
    const lineErrors = Object.entries(errors).filter(([key]) =>
        key.startsWith('items.'),
    );

    const title = editing ? `Sửa đơn hàng ${order?.code}` : 'Tạo đơn hàng';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref={editing ? `/orders/${order?.id}` : '/orders'}
                backLabel={
                    editing
                        ? 'Quay lại chi tiết đơn hàng'
                        : 'Quay lại danh sách đơn hàng'
                }
                onSubmit={() => save(primaryThen)}
                processing={form.processing}
                submitLabel={
                    editing
                        ? 'Lưu (F1)'
                        : canApprove
                          ? 'Tạo đơn và duyệt (F1)'
                          : 'Tạo đơn hàng (F1)'
                }
                extraActions={
                    !editing && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={form.processing}
                                    aria-label="Cách lưu khác"
                                >
                                    Lưu khác <ChevronDown className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {canApprove && (
                                    <DropdownMenuItem
                                        onSelect={() => save(null)}
                                    >
                                        Lưu nháp (chưa duyệt)
                                    </DropdownMenuItem>
                                )}
                                {canApprove && canShip && (
                                    <DropdownMenuItem
                                        onSelect={() => save('export')}
                                    >
                                        Tạo đơn và xuất kho
                                    </DropdownMenuItem>
                                )}
                                {!canApprove && (
                                    <DropdownMenuItem disabled>
                                        Bạn chưa có quyền duyệt đơn
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )
                }
                aside={
                    <>
                        <FormSection title="Thông tin bổ sung">
                            <Field label="Chi nhánh *" error={errors.branch_id}>
                                <OptionSelect
                                    value={
                                        data.branch_id
                                            ? String(data.branch_id)
                                            : null
                                    }
                                    onChange={(value) =>
                                        form.setData(
                                            'branch_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                    options={toSelectOptions(branches)}
                                    disabled={editing}
                                />
                            </Field>
                            <Field label="Bán bởi" error={errors.assigned_to}>
                                <OptionSelect
                                    value={
                                        data.assigned_to
                                            ? String(data.assigned_to)
                                            : null
                                    }
                                    onChange={(value) =>
                                        form.setData(
                                            'assigned_to',
                                            value ? Number(value) : null,
                                        )
                                    }
                                    options={toSelectOptions(staff)}
                                    emptyLabel="Người tạo đơn"
                                />
                            </Field>
                            <Field label="Nguồn đơn" error={errors.source_id}>
                                <OptionSelect
                                    value={
                                        data.source_id
                                            ? String(data.source_id)
                                            : null
                                    }
                                    onChange={(value) =>
                                        form.setData(
                                            'source_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                    options={toSelectOptions(sources)}
                                    emptyLabel="Không chọn"
                                />
                            </Field>
                            <Field
                                label="Hẹn giao"
                                error={errors.expected_delivery_at}
                            >
                                <Input
                                    value={data.expected_delivery_at}
                                    placeholder="dd/mm/yyyy"
                                    onChange={(event) =>
                                        form.setData(
                                            'expected_delivery_at',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Mã đơn tham chiếu"
                                error={errors.reference}
                            >
                                <Input
                                    value={data.reference}
                                    onChange={(event) =>
                                        form.setData(
                                            'reference',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </FormSection>
                        <FormSection title="Ghi chú">
                            <Textarea
                                value={data.note}
                                placeholder="VD: Giao hàng giờ hành chính"
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                            />
                            <Field label="Tag" error={errors.tags}>
                                <Input
                                    value={data.tags}
                                    placeholder="Cách nhau bởi dấu phẩy"
                                    onChange={(event) =>
                                        form.setData('tags', event.target.value)
                                    }
                                />
                            </Field>
                        </FormSection>
                    </>
                }
            >
                <PageErrors
                    keys={['items', 'stock', 'status', 'payments', 'amount']}
                />
                <FormSection title="Thông tin khách hàng">
                    <CustomerPicker
                        value={data.customer}
                        onChange={pickCustomer}
                        error={errors.customer_id}
                    />
                    {data.customer &&
                        data.customer.addresses.length > 1 &&
                        data.delivery_method !== 'pickup' && (
                            <Field label="Địa chỉ giao hàng">
                                <OptionSelect
                                    value={
                                        String(
                                            data.customer.addresses.find(
                                                (address) =>
                                                    address.address ===
                                                    data.shipping_address,
                                            )?.id ?? '',
                                        ) || null
                                    }
                                    onChange={(value) => {
                                        const address =
                                            data.customer?.addresses.find(
                                                (item) =>
                                                    String(item.id) === value,
                                            );

                                        if (address) {
                                            form.setData((previous) => ({
                                                ...previous,
                                                shipping_address:
                                                    address.address,
                                                receiver_name:
                                                    address.name ??
                                                    previous.receiver_name,
                                                receiver_phone:
                                                    address.phone ??
                                                    previous.receiver_phone,
                                            }));
                                        }
                                    }}
                                    options={data.customer.addresses.map(
                                        (address) => ({
                                            value: String(address.id),
                                            label: address.address,
                                        }),
                                    )}
                                    placeholder="Chọn địa chỉ đã lưu"
                                />
                            </Field>
                        )}
                </FormSection>

                <section className="rounded-xl border bg-card">
                    <div className="flex flex-wrap items-center gap-3 border-b px-5 py-4">
                        <h3 className="font-semibold">Sản phẩm</h3>
                        <div className="ml-auto flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground">
                                Chính sách giá
                            </span>
                            <OptionSelect
                                value={data.price_policy}
                                onChange={(value) =>
                                    value && applyPolicy(value, data.items)
                                }
                                options={pricePolicies}
                                className="h-8 w-44"
                            />
                        </div>
                    </div>
                    <div className="flex gap-2 px-5 pt-4">
                        <OrderProductSearch
                            ref={search}
                            className="flex-1"
                            onPick={addVariant}
                            branchId={data.branch_id}
                            pricePolicy={data.price_policy}
                            autoFocus={!editing && customer0 !== null}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setServiceOpen(true)}
                            title="F9"
                        >
                            <Plus /> Thêm dịch vụ (F9)
                        </Button>
                    </div>
                    {data.items.length === 0 ? (
                        <p
                            className={cn(
                                'px-5 py-10 text-center text-sm',
                                errors.items
                                    ? 'text-destructive'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {errors.items ??
                                'Đơn hàng chưa có sản phẩm. Tìm hoặc quét mã vạch để thêm (F3).'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto px-5 py-4">
                            <table className="w-full text-sm">
                                <thead className="text-left text-muted-foreground">
                                    <tr className="border-b">
                                        <th className="w-8 py-2 font-normal">
                                            STT
                                        </th>
                                        <th className="py-2 font-normal">
                                            Sản phẩm
                                        </th>
                                        <th className="w-28 py-2 text-right font-normal">
                                            Số lượng
                                        </th>
                                        <th className="w-32 py-2 text-right font-normal">
                                            Đơn giá
                                        </th>
                                        <th className="w-28 py-2 text-right font-normal">
                                            Chiết khấu
                                        </th>
                                        <th className="w-32 py-2 text-right font-normal">
                                            Thành tiền
                                        </th>
                                        <th className="w-8" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.items.map((line, index) => {
                                        const short =
                                            line.available != null &&
                                            line.type !== 'service' &&
                                            line.quantity > line.available;

                                        return (
                                            <tr
                                                key={line.key}
                                                className="border-b align-top last:border-0"
                                            >
                                                <td className="py-3 text-muted-foreground">
                                                    {index + 1}
                                                </td>
                                                <td className="py-3">
                                                    <div className="flex gap-3">
                                                        <ProductThumb
                                                            url={line.image_url}
                                                            className="size-10"
                                                        />
                                                        <div className="min-w-0">
                                                            <div className="font-medium">
                                                                {line.name}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {line.variant_id
                                                                    ? line.sku
                                                                    : 'Dịch vụ khác'}
                                                                {line.unit
                                                                    ? ` · ${line.unit}`
                                                                    : ''}
                                                            </div>
                                                            <input
                                                                className="mt-1 w-full bg-transparent text-xs text-muted-foreground outline-none placeholder:text-muted-foreground/60"
                                                                placeholder="Ghi chú sản phẩm"
                                                                value={
                                                                    line.note ??
                                                                    ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateLine(
                                                                        line.key,
                                                                        {
                                                                            note: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="py-3">
                                                    <Input
                                                        ref={(element) => {
                                                            quantityInputs.current[
                                                                line.key
                                                            ] = element;
                                                        }}
                                                        inputMode="numeric"
                                                        className={cn(
                                                            'h-8 text-right',
                                                            short &&
                                                                'border-warning text-warning',
                                                        )}
                                                        value={line.quantity}
                                                        aria-invalid={
                                                            !!errors[
                                                                `items.${index}.quantity`
                                                            ]
                                                        }
                                                        onChange={(event) =>
                                                            updateLine(
                                                                line.key,
                                                                {
                                                                    quantity:
                                                                        Math.max(
                                                                            0,
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
                                                        onKeyDown={(event) => {
                                                            if (
                                                                event.key ===
                                                                'Enter'
                                                            ) {
                                                                event.preventDefault();
                                                                search.current?.focus();
                                                            }
                                                        }}
                                                    />
                                                    {line.available != null &&
                                                        line.type !==
                                                            'service' && (
                                                            <div
                                                                className={cn(
                                                                    'mt-1 text-right text-xs',
                                                                    short
                                                                        ? 'text-warning'
                                                                        : 'text-muted-foreground',
                                                                )}
                                                            >
                                                                Có thể bán:{' '}
                                                                {formatNumber(
                                                                    line.available,
                                                                )}
                                                            </div>
                                                        )}
                                                </td>
                                                <td className="py-3">
                                                    <MoneyInput
                                                        className="h-8 text-right"
                                                        value={line.price}
                                                        disabled={
                                                            !canEditPrice &&
                                                            line.variant_id !==
                                                                null
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            updateLine(
                                                                line.key,
                                                                {
                                                                    price:
                                                                        value ??
                                                                        0,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="py-3">
                                                    <MoneyInput
                                                        className="h-8 text-right"
                                                        value={line.discount}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            updateLine(
                                                                line.key,
                                                                {
                                                                    discount:
                                                                        value ??
                                                                        0,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="py-3 text-right font-medium tabular-nums">
                                                    {formatNumber(
                                                        lineTotal(line),
                                                    )}
                                                </td>
                                                <td className="py-2 text-right">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7 text-muted-foreground"
                                                        aria-label="Xóa dòng"
                                                        onClick={() =>
                                                            setItems(
                                                                data.items.filter(
                                                                    (item) =>
                                                                        item.key !==
                                                                        line.key,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <X className="size-4" />
                                                    </Button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            {lineErrors.length > 0 && (
                                <p className="mt-2 text-sm text-destructive">
                                    {lineErrors[0][1]}
                                </p>
                            )}
                        </div>
                    )}
                    <div className="grid gap-2 border-t px-5 py-4 text-sm sm:ml-auto sm:w-96">
                        <SumLine
                            label={`Tổng tiền hàng (${formatNumber(totals.quantity)} sản phẩm)`}
                            value={totals.subtotal}
                        />
                        <SumLine
                            label={
                                <button
                                    type="button"
                                    className="text-primary hover:underline"
                                    onClick={() => setDiscountOpen(true)}
                                >
                                    Chiết khấu (F6)
                                    {data.discount_bp !== null &&
                                    data.discount_bp > 0
                                        ? ` ${String(data.discount_bp / 100).replace('.', ',')}%`
                                        : ''}
                                </button>
                            }
                            value={totals.discount}
                        />
                        {data.delivery_method !== 'pickup' && (
                            <div className="flex items-center justify-between gap-3">
                                <span>Phí giao hàng (F7)</span>
                                <MoneyInput
                                    ref={shippingInput}
                                    className="h-8 w-36 text-right"
                                    value={data.shipping_fee}
                                    onValueChange={(value) =>
                                        form.setData('shipping_fee', value ?? 0)
                                    }
                                />
                            </div>
                        )}
                        <SumLine
                            label="Khách phải trả"
                            value={totals.total}
                            strong
                        />
                        {editing && alreadyPaid !== 0 && (
                            <>
                                <SumLine
                                    label="Đã thanh toán"
                                    value={alreadyPaid}
                                />
                                <SumLine
                                    label="Còn phải trả"
                                    value={remaining}
                                />
                            </>
                        )}
                    </div>
                </section>

                {!editing && can('orders.pay') && (
                    <FormSection title="Thanh toán">
                        <div className="flex flex-wrap gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    checked={!paying}
                                    onChange={() => setPaying(false)}
                                />
                                Thanh toán sau
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    checked={paying}
                                    onChange={() => {
                                        setPaying(true);
                                        setPaymentTouched(false);
                                    }}
                                />
                                Đã thanh toán
                            </label>
                        </div>
                        {paying && (
                            <div className="grid gap-2">
                                {data.payments.map((payment, index) => (
                                    <div
                                        key={index}
                                        className="flex items-center gap-2"
                                    >
                                        <OptionSelect
                                            value={
                                                payment.payment_method_id
                                                    ? String(
                                                          payment.payment_method_id,
                                                      )
                                                    : null
                                            }
                                            onChange={(value) =>
                                                form.setData(
                                                    'payments',
                                                    data.payments.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      payment_method_id:
                                                                          value
                                                                              ? Number(
                                                                                    value,
                                                                                )
                                                                              : null,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                            options={toSelectOptions(
                                                paymentMethods,
                                            )}
                                            placeholder="Hình thức thanh toán"
                                            className="flex-1"
                                        />
                                        <MoneyInput
                                            className="w-40 text-right"
                                            value={payment.amount}
                                            onValueChange={(value) => {
                                                setPaymentTouched(true);
                                                form.setData(
                                                    'payments',
                                                    data.payments.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      amount:
                                                                          value ??
                                                                          0,
                                                                  }
                                                                : item,
                                                    ),
                                                );
                                            }}
                                        />
                                        {data.payments.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Bỏ dòng thanh toán"
                                                onClick={() =>
                                                    form.setData(
                                                        'payments',
                                                        data.payments.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <Button
                                        type="button"
                                        variant="link"
                                        className="px-0"
                                        onClick={() => {
                                            setPaymentTouched(true);
                                            form.setData('payments', [
                                                ...data.payments,
                                                {
                                                    payment_method_id:
                                                        defaultMethod,
                                                    amount: Math.max(
                                                        0,
                                                        totals.total - paidNow,
                                                    ),
                                                },
                                            ]);
                                        }}
                                    >
                                        <Plus /> Thêm phương thức
                                    </Button>
                                    <span>
                                        Khách đã trả{' '}
                                        <b className="tabular-nums">
                                            {formatNumber(paidNow)}
                                        </b>
                                        {' · '}
                                        {paidNow >= totals.total ? (
                                            <>
                                                Tiền thừa{' '}
                                                <b className="tabular-nums">
                                                    {formatNumber(
                                                        paidNow - totals.total,
                                                    )}
                                                </b>
                                            </>
                                        ) : (
                                            <>
                                                Còn phải trả{' '}
                                                <b className="text-destructive tabular-nums">
                                                    {formatNumber(
                                                        totals.total - paidNow,
                                                    )}
                                                </b>
                                            </>
                                        )}
                                    </span>
                                </div>
                                {paidNow > totals.total && (
                                    <p className="text-xs text-muted-foreground">
                                        Số tiền ghi nhận tối đa bằng số khách
                                        phải trả; phần thừa là tiền trả lại
                                        khách.
                                    </p>
                                )}
                            </div>
                        )}
                    </FormSection>
                )}

                <FormSection title="Đóng gói và giao hàng">
                    <div className="grid gap-2 sm:grid-cols-4">
                        {deliveryOptions.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                disabled={option.soon}
                                onClick={() =>
                                    form.setData(
                                        'delivery_method',
                                        option.value,
                                    )
                                }
                                className={cn(
                                    'rounded-md border px-3 py-2.5 text-left text-sm',
                                    data.delivery_method === option.value
                                        ? 'border-primary bg-accent font-medium text-primary'
                                        : 'hover:border-primary/50',
                                    option.soon &&
                                        'cursor-not-allowed opacity-50',
                                )}
                            >
                                {option.label}
                                {option.soon && (
                                    <span className="block text-xs font-normal">
                                        Sắp có
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                    {data.delivery_method === 'pickup' ? (
                        <p className="text-sm text-muted-foreground">
                            Khách nhận hàng tại cửa hàng: đơn hoàn thành ngay
                            khi xuất kho.
                        </p>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Người nhận"
                                error={errors.receiver_name}
                            >
                                <Input
                                    value={data.receiver_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'receiver_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Số điện thoại nhận"
                                error={errors.receiver_phone}
                            >
                                <Input
                                    value={data.receiver_phone}
                                    inputMode="tel"
                                    onChange={(event) =>
                                        form.setData(
                                            'receiver_phone',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Địa chỉ giao hàng"
                                error={errors.shipping_address}
                                className="sm:col-span-2"
                            >
                                <Input
                                    value={data.shipping_address}
                                    onChange={(event) =>
                                        form.setData(
                                            'shipping_address',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            {data.delivery_method === 'external' && (
                                <>
                                    <Field
                                        label="Đối tác vận chuyển"
                                        error={errors.carrier_name}
                                    >
                                        <Input
                                            value={data.carrier_name}
                                            placeholder="VD: Viettel Post"
                                            onChange={(event) =>
                                                form.setData(
                                                    'carrier_name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Mã vận đơn"
                                        error={errors.tracking_code}
                                    >
                                        <Input
                                            value={data.tracking_code}
                                            onChange={(event) =>
                                                form.setData(
                                                    'tracking_code',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                        </div>
                    )}
                </FormSection>
            </DocumentForm>

            <DiscountDialog
                open={discountOpen}
                onOpenChange={setDiscountOpen}
                subtotal={totals.subtotal}
                discount={data.discount}
                discountBp={data.discount_bp}
                onApply={(discount, discountBp) =>
                    form.setData((previous) => ({
                        ...previous,
                        discount,
                        discount_bp: discountBp,
                    }))
                }
            />
            <ServiceDialog
                open={serviceOpen}
                onOpenChange={setServiceOpen}
                onAdd={(name, price, quantity) => {
                    const key = nextKey();
                    setItems([
                        ...data.items,
                        {
                            key,
                            variant_id: null,
                            product_id: null,
                            name,
                            sku: '',
                            unit: null,
                            image_url: null,
                            type: 'service',
                            quantity,
                            price,
                            discount: 0,
                            note: null,
                            available: null,
                        },
                    ]);
                }}
            />
        </>
    );
}

function SumLine({
    label,
    value,
    strong = false,
}: {
    label: React.ReactNode;
    value: number;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-between gap-3',
                strong && 'text-base font-semibold',
            )}
        >
            <span>{label}</span>
            <span className="tabular-nums">{formatNumber(value)}</span>
        </div>
    );
}

/** F6: order discount as an amount or a percentage. */
function DiscountDialog({
    open,
    onOpenChange,
    subtotal,
    discount,
    discountBp,
    onApply,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    subtotal: number;
    discount: number;
    discountBp: number | null;
    onApply: (discount: number, discountBp: number | null) => void;
}) {
    const [mode, setMode] = useState<'amount' | 'percent'>('amount');
    const [amount, setAmount] = useState(0);
    const [bp, setBp] = useState<number | null>(null);

    useEffect(() => {
        if (open) {
            setMode(discountBp !== null ? 'percent' : 'amount');
            setAmount(discount);
            setBp(discountBp);
        }
    }, [open, discount, discountBp]);

    const apply = () => {
        if (mode === 'percent') {
            onApply(0, bp ?? 0);
        } else {
            onApply(Math.min(amount, subtotal), null);
        }

        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-sm">
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        apply();
                    }}
                >
                    <DialogTitle>Chiết khấu đơn hàng</DialogTitle>
                    <div className="flex gap-2">
                        {(['amount', 'percent'] as const).map((value) => (
                            <Button
                                key={value}
                                type="button"
                                size="sm"
                                variant={mode === value ? 'default' : 'outline'}
                                onClick={() => setMode(value)}
                            >
                                {value === 'amount'
                                    ? 'Giá trị (đ)'
                                    : 'Phần trăm (%)'}
                            </Button>
                        ))}
                    </div>
                    {mode === 'amount' ? (
                        <MoneyInput
                            autoFocus
                            value={amount}
                            onValueChange={(value) => setAmount(value ?? 0)}
                        />
                    ) : (
                        <PercentInput
                            autoFocus
                            value={bp}
                            onValueChange={setBp}
                        />
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Hủy
                        </Button>
                        <Button>Áp dụng</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** F9: a free-text service line (lắp đặt, phí dịch vụ...). */
function ServiceDialog({
    open,
    onOpenChange,
    onAdd,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAdd: (name: string, price: number, quantity: number) => void;
}) {
    const [name, setName] = useState('');
    const [price, setPrice] = useState(0);
    const [quantity, setQuantity] = useState(1);

    useEffect(() => {
        if (open) {
            setName('');
            setPrice(0);
            setQuantity(1);
        }
    }, [open]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        event.stopPropagation();

                        if (name.trim() !== '') {
                            onAdd(name.trim(), price, Math.max(1, quantity));
                            onOpenChange(false);
                        }
                    }}
                >
                    <DialogTitle>Thêm dịch vụ khác</DialogTitle>
                    <Field label="Tên dịch vụ *">
                        <Input
                            autoFocus
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Đơn giá">
                            <MoneyInput
                                value={price}
                                onValueChange={(value) => setPrice(value ?? 0)}
                            />
                        </Field>
                        <Field label="Số lượng">
                            <Input
                                inputMode="numeric"
                                value={quantity}
                                onChange={(event) =>
                                    setQuantity(
                                        Number(
                                            event.target.value.replace(
                                                /\D/g,
                                                '',
                                            ),
                                        ) || 1,
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Hủy
                        </Button>
                        <Button disabled={name.trim() === ''}>Thêm</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

OrderForm.layout = {
    breadcrumbs: [
        { title: 'Đơn hàng', href: '/orders' },
        { title: 'Tạo đơn hàng', href: '#' },
    ],
};
