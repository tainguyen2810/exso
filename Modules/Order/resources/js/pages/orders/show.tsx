import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, ChevronDown, Copy, Printer } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import type { CustomerOption } from '@/components/app/customer-picker';
import { DocumentView } from '@/components/app/document-view';
import { Field } from '@/components/app/field';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { ProductThumb } from '@/components/app/product-thumb';
import { Pill, StatusPill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    deliveryStatuses,
    orderStatuses,
    paymentStatuses,
} from '../../components/statuses';
import { PaymentDialog } from '../../components/payment-dialog';
import {
    ACTIVE_SHIPMENT,
    ShipmentDialog,
    codStatuses,
    shipmentStatuses,
} from '../../components/shipping';
import type {
    Receiver,
    ShipmentDraft,
    ShipmentSummary,
    ShippingOptions,
} from '../../components/shipping';
import type {
    PaymentMethodOption,
    QrInfo,
} from '../../components/payment-dialog';

type Order = {
    id: number;
    code: string;
    channel: string;
    status: string;
    status_label: string;
    payment_status: string | null;
    delivery_status: string | null;
    customer: CustomerOption | null;
    customer_name: string | null;
    customer_phone: string | null;
    receiver_name: string | null;
    receiver_phone: string | null;
    shipping_address: string | null;
    branch: string;
    source: string | null;
    assignee: string | null;
    creator: string | null;
    price_label: string | null;
    delivery_method: string;
    delivery_label: string;
    carrier_name: string | null;
    tracking_code: string | null;
    subtotal: number;
    discount: number;
    discount_bp: number | null;
    promotion_discount: number;
    promotions: {
        promotion_id: number | null;
        coupon_id: number | null;
        name: string;
        code: string | null;
        amount: number;
    }[];
    shipping_fee: number;
    total: number;
    paid: number;
    returned_total: number;
    remaining: number;
    note: string | null;
    tags: string | null;
    reference: string | null;
    expected_delivery_at: string | null;
    sale_at: string | null;
    cancel_reason: string | null;
    cancel_note: string | null;
    steps: Record<string, string | null>;
    items: {
        id: number;
        variant_id: number | null;
        name: string;
        sku: string | null;
        unit: string | null;
        image_url: string | null;
        product_type: string;
        quantity: number;
        returned_quantity: number;
        price: number;
        discount: number;
        promotion_discount: number;
        is_gift: boolean;
        total: number;
        note: string | null;
        available: number | null;
    }[];
    payments: {
        id: number;
        method: string;
        kind: string;
        amount: number;
        note: string | null;
        creator: string | null;
        paid_at: string;
    }[];
    histories: {
        id: number;
        action: string;
        note: string | null;
        creator: string | null;
        created_at: string | null;
    }[];
    returns: {
        id: number;
        code: string;
        total: number;
        refunded: number;
        quantity: number;
        creator: string | null;
        created_at: string | null;
    }[];
    qr: QrInfo | null;
};

const STEPS = [
    ['pending', 'Đặt hàng'],
    ['approved', 'Duyệt'],
    ['packed', 'Đóng gói'],
    ['shipped', 'Xuất kho'],
    ['completed', 'Hoàn thành'],
] as const;

const ORDER = ['pending', 'approved', 'packed', 'shipped', 'completed'];

/** A card added by another module (order.panels), e.g. e-invoice, marketplace. */
type Panel = {
    title: string;
    href?: string | null;
    lines: { label: string; value: string; href?: string | null }[];
    actions?: { label: string; href: string; method?: 'get' | 'post' }[];
};

/** Chi tiết đơn hàng like Sapo: 5-step bar, one block per job, history. */
export default function OrderShow({
    order,
    paymentMethods,
    defaultMethod,
    cancelReasons,
    requireCancelReason,
    allowCancelAfterExport,
    shipping = { enabled: false },
    panels = [],
    locked = null,
}: {
    order: Order;
    paymentMethods: PaymentMethodOption[];
    defaultMethod: number | string | null;
    cancelReasons: { id: number; name: string }[];
    requireCancelReason: boolean;
    allowCancelAfterExport: boolean;
    shipping?: ShippingOptions & {
        shipments?: ShipmentSummary[];
        defaults?: (Receiver & Partial<ShipmentDraft>) | null;
    };
    panels?: Panel[];
    /** Why the order cannot be changed or cancelled (e.g. e-invoice issued). */
    locked?: string | null;
}) {
    const can = useCan();
    const [paying, setPaying] = useState(false);
    const [shipping0, setShipping0] = useState(false);
    const shipments = shipping.shipments ?? [];
    const active = shipments.find((item) =>
        ACTIVE_SHIPMENT.includes(item.status),
    );
    const [cancelling, setCancelling] = useState(false);
    const cancelled = order.status === 'cancelled';
    const exported = ['shipped', 'completed'].includes(order.status);
    const editable =
        ['pending', 'approved', 'packed'].includes(order.status) && !locked;
    const canCancel =
        can('orders.cancel') &&
        !locked &&
        !cancelled &&
        order.status !== 'completed' &&
        (!exported || allowCancelAfterExport);
    const canReturn =
        can('returns.create') &&
        exported &&
        order.items.some(
            (item) => item.variant_id && item.quantity > item.returned_quantity,
        );
    const step = (action: string) => `/orders/${order.id}/${action}`;

    return (
        <>
            <Head title={`Đơn hàng ${order.code}`} />
            <DocumentView
                title={order.code}
                badges={
                    <>
                        <StatusPill
                            statuses={orderStatuses}
                            value={order.status}
                        />
                        <StatusPill
                            statuses={paymentStatuses}
                            value={order.payment_status}
                        />
                        {order.channel === 'pos' && <Pill>Bán tại quầy</Pill>}
                    </>
                }
                meta={`${formatDateTime(order.sale_at)} · ${order.branch}${order.source ? ` · ${order.source}` : ''}`}
                backHref="/orders"
                backLabel="Quay lại danh sách đơn hàng"
                actions={
                    <>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline">
                                    <Printer /> In đơn{' '}
                                    <ChevronDown className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {(
                                    [
                                        ['a4', 'Hóa đơn A4'],
                                        ['a5', 'Hóa đơn A5'],
                                        ['80', 'Hóa đơn K80 (80mm)'],
                                        ['58', 'Hóa đơn K58 (58mm)'],
                                    ] as const
                                ).map(([size, label]) => (
                                    <DropdownMenuItem
                                        key={size}
                                        onSelect={() =>
                                            window.open(
                                                `/orders/${order.id}/print?size=${size}`,
                                                '_blank',
                                            )
                                        }
                                    >
                                        {label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        {can('orders.create') && (
                            <Button variant="outline" asChild>
                                <Link href={`/orders/create?copy=${order.id}`}>
                                    <Copy /> Sao chép
                                </Link>
                            </Button>
                        )}
                        {canReturn && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/returns/create?order=${order.id}`}
                                >
                                    Đổi trả hàng
                                </Link>
                            </Button>
                        )}
                        {editable && can('orders.update') && (
                            <Button variant="outline" asChild>
                                <Link href={`/orders/${order.id}/edit`}>
                                    Sửa đơn
                                </Link>
                            </Button>
                        )}
                        {canCancel && (
                            <Button
                                variant="outline"
                                className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                onClick={() => setCancelling(true)}
                            >
                                Hủy đơn
                            </Button>
                        )}
                    </>
                }
                aside={
                    <>
                        <Card className="gap-0 py-0">
                            <CardHeader className="border-b px-5 py-4">
                                <CardTitle>Thông tin đơn hàng</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2 px-5 py-4 text-sm">
                                <Line label="Chi nhánh">{order.branch}</Line>
                                <Line label="Nguồn đơn">
                                    {order.source ??
                                        (order.channel === 'pos' ? 'POS' : '—')}
                                </Line>
                                <Line label="Bán bởi">
                                    {order.assignee ?? '—'}
                                </Line>
                                <Line label="Người tạo">
                                    {order.creator ?? '—'}
                                </Line>
                                <Line label="Chính sách giá">
                                    {order.price_label ?? '—'}
                                </Line>
                                {order.expected_delivery_at && (
                                    <Line label="Hẹn giao">
                                        {order.expected_delivery_at}
                                    </Line>
                                )}
                                {order.reference && (
                                    <Line label="Mã tham chiếu">
                                        {order.reference}
                                    </Line>
                                )}
                            </CardContent>
                        </Card>
                        {(order.note || order.tags) && (
                            <Card className="gap-2 px-5 py-4 text-sm">
                                <CardTitle>Ghi chú</CardTitle>
                                {order.note && (
                                    <p className="whitespace-pre-line">
                                        {order.note}
                                    </p>
                                )}
                                {order.tags && (
                                    <div className="flex flex-wrap gap-1.5">
                                        {order.tags.split(',').map((tag) => (
                                            <Pill key={tag}>{tag.trim()}</Pill>
                                        ))}
                                    </div>
                                )}
                            </Card>
                        )}
                        {panels.map((panel) => (
                            <Card key={panel.title} className="gap-0 py-0">
                                <CardHeader className="flex-row items-center justify-between border-b px-5 py-4">
                                    <CardTitle>{panel.title}</CardTitle>
                                    {panel.href && (
                                        <Link
                                            href={panel.href}
                                            className="text-sm text-primary hover:underline"
                                        >
                                            Xem
                                        </Link>
                                    )}
                                </CardHeader>
                                <CardContent className="grid gap-2 px-5 py-4 text-sm">
                                    {panel.lines.map((line) => (
                                        <Line key={line.label} label={line.label}>
                                            {line.href ? (
                                                <Link
                                                    href={line.href}
                                                    className="text-primary hover:underline"
                                                >
                                                    {line.value}
                                                </Link>
                                            ) : (
                                                line.value
                                            )}
                                        </Line>
                                    ))}
                                    {(panel.actions ?? []).map((action) => (
                                        <Button
                                            key={action.label}
                                            variant="outline"
                                            size="sm"
                                            className="mt-1 justify-self-start"
                                            asChild
                                        >
                                            <Link
                                                href={action.href}
                                                method={action.method ?? 'get'}
                                                as="button"
                                            >
                                                {action.label}
                                            </Link>
                                        </Button>
                                    ))}
                                </CardContent>
                            </Card>
                        ))}
                        <Card className="gap-0 py-0">
                            <CardHeader className="border-b px-5 py-4">
                                <CardTitle>Lịch sử đơn hàng</CardTitle>
                            </CardHeader>
                            <CardContent className="px-5 py-4">
                                <ol className="relative grid gap-3 border-l pl-4 text-sm">
                                    {[...order.histories]
                                        .reverse()
                                        .map((history) => (
                                            <li
                                                key={history.id}
                                                className="relative"
                                            >
                                                <span className="absolute top-1.5 -left-[21px] size-2 rounded-full bg-primary" />
                                                <div className="font-medium">
                                                    {history.action}
                                                </div>
                                                {history.note && (
                                                    <div className="text-muted-foreground">
                                                        {history.note}
                                                    </div>
                                                )}
                                                <div className="text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        history.created_at,
                                                    )}
                                                    {history.creator
                                                        ? ` · ${history.creator}`
                                                        : ''}
                                                </div>
                                            </li>
                                        ))}
                                </ol>
                            </CardContent>
                        </Card>
                    </>
                }
            >
                <StepBar order={order} />

                {cancelled && (
                    <div className="rounded-md border bg-muted px-4 py-3 text-sm">
                        Đơn hàng đã hủy lúc{' '}
                        {formatDateTime(order.steps.cancelled)}
                        {order.cancel_reason
                            ? `. Lý do: ${order.cancel_reason}`
                            : ''}
                        {order.cancel_note ? ` (${order.cancel_note})` : ''}.
                    </div>
                )}

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle>Thông tin khách hàng</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 px-5 py-4 text-sm sm:grid-cols-2">
                        <div className="grid gap-1">
                            {order.customer ? (
                                <Link
                                    href={`/customers/${order.customer.id}`}
                                    className="font-medium text-primary hover:underline"
                                >
                                    {order.customer.name}
                                </Link>
                            ) : (
                                <span className="font-medium">
                                    {order.customer_name ?? 'Khách lẻ'}
                                </span>
                            )}
                            {order.customer_phone && (
                                <span className="text-muted-foreground">
                                    {order.customer_phone}
                                </span>
                            )}
                            {order.customer && (
                                <span>
                                    Nợ phải thu:{' '}
                                    <span
                                        className={cn(
                                            'tabular-nums',
                                            order.customer.debt > 0 &&
                                                'text-destructive',
                                        )}
                                    >
                                        {formatNumber(order.customer.debt)}
                                    </span>
                                </span>
                            )}
                        </div>
                        {order.delivery_method !== 'pickup' &&
                            (order.shipping_address || order.receiver_name) && (
                                <div className="grid gap-1">
                                    <span className="text-muted-foreground">
                                        Địa chỉ giao hàng
                                    </span>
                                    <span>
                                        {order.receiver_name}
                                        {order.receiver_phone
                                            ? ` · ${order.receiver_phone}`
                                            : ''}
                                    </span>
                                    <span>{order.shipping_address}</span>
                                </div>
                            )}
                    </CardContent>
                </Card>

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle>Sản phẩm</CardTitle>
                    </CardHeader>
                    <div className="overflow-x-auto px-5 py-2">
                        <table className="w-full text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr className="border-b">
                                    <th className="py-2 font-normal">
                                        Sản phẩm
                                    </th>
                                    <th className="py-2 text-right font-normal">
                                        Số lượng
                                    </th>
                                    <th className="py-2 text-right font-normal">
                                        Đơn giá
                                    </th>
                                    <th className="py-2 text-right font-normal">
                                        Chiết khấu
                                    </th>
                                    <th className="py-2 text-right font-normal">
                                        Thành tiền
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.items.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="border-b align-top last:border-0"
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
                                                        {item.sku ??
                                                            'Dịch vụ khác'}
                                                        {item.unit
                                                            ? ` · ${item.unit}`
                                                            : ''}
                                                    </div>
                                                    {item.is_gift ? (
                                                        <div className="text-xs text-success">
                                                            Quà tặng khuyến mại
                                                        </div>
                                                    ) : (
                                                        item.note && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {item.note}
                                                            </div>
                                                        )
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="py-3 text-right tabular-nums">
                                            {formatNumber(item.quantity)}
                                            {item.returned_quantity > 0 && (
                                                <div className="text-xs text-warning">
                                                    Đã trả{' '}
                                                    {formatNumber(
                                                        item.returned_quantity,
                                                    )}
                                                </div>
                                            )}
                                            {item.available !== null &&
                                                item.available <
                                                    item.quantity &&
                                                order.status === 'pending' && (
                                                    <div className="text-xs text-warning">
                                                        Có thể bán{' '}
                                                        {formatNumber(
                                                            item.available,
                                                        )}
                                                    </div>
                                                )}
                                        </td>
                                        <td className="py-3 text-right tabular-nums">
                                            {formatNumber(item.price)}
                                        </td>
                                        <td className="py-3 text-right tabular-nums">
                                            {item.discount > 0
                                                ? formatNumber(item.discount)
                                                : ''}
                                            {item.promotion_discount > 0 && (
                                                <div className="text-xs text-success">
                                                    gồm KM{' '}
                                                    {formatNumber(
                                                        item.promotion_discount,
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                        <td className="py-3 text-right tabular-nums">
                                            {formatNumber(item.total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="grid gap-1.5 border-t px-5 py-4 text-sm sm:ml-auto sm:w-96">
                        <Sum label="Tổng tiền hàng" value={order.subtotal} />
                        <Sum
                            label={`Chiết khấu${order.discount_bp ? ` (${String(order.discount_bp / 100).replace('.', ',')}%)` : ''}`}
                            value={order.discount}
                        />
                        {order.promotions.map((promotion) => (
                            <div
                                key={`${promotion.promotion_id}-${promotion.coupon_id}`}
                                className="flex justify-between gap-3 pl-3 text-xs text-success"
                            >
                                <span>
                                    {promotion.coupon_id !== null
                                        ? `Mã giảm giá ${promotion.code}`
                                        : promotion.name}
                                </span>
                                <span className="tabular-nums">
                                    {promotion.amount > 0
                                        ? `−${formatNumber(promotion.amount)}`
                                        : 'Quà tặng'}
                                </span>
                            </div>
                        ))}
                        <Sum label="Phí giao hàng" value={order.shipping_fee} />
                        <Sum
                            label="Khách phải trả"
                            value={order.total}
                            strong
                        />
                        {order.returned_total > 0 && (
                            <Sum
                                label="Hàng trả lại"
                                value={-order.returned_total}
                            />
                        )}
                    </div>
                </Card>

                <Block
                    done={order.payment_status === 'paid'}
                    title={
                        order.payment_status === 'paid'
                            ? 'Đơn hàng đã thanh toán'
                            : order.paid > 0
                              ? 'Đơn hàng thanh toán một phần'
                              : 'Đơn hàng chưa thanh toán'
                    }
                    action={
                        !cancelled &&
                        order.remaining > 0 &&
                        can('orders.pay') && (
                            <Button onClick={() => setPaying(true)}>
                                Thanh toán
                            </Button>
                        )
                    }
                >
                    <div className="grid gap-1.5 text-sm">
                        <Sum
                            label="Khách phải trả"
                            value={order.total - order.returned_total}
                        />
                        <Sum label="Đã thanh toán" value={order.paid} />
                        {!cancelled && (
                            <Sum
                                label="Còn phải trả"
                                value={order.remaining}
                                strong
                            />
                        )}
                    </div>
                    {order.payments.length > 0 && (
                        <ul className="mt-3 grid gap-1 border-t pt-3 text-sm">
                            {order.payments.map((payment) => (
                                <li
                                    key={payment.id}
                                    className="flex justify-between gap-3"
                                >
                                    <span>
                                        {payment.kind === 'refund'
                                            ? 'Hoàn tiền'
                                            : 'Thanh toán'}{' '}
                                        · {payment.method}
                                        <span className="text-xs text-muted-foreground">
                                            {' '}
                                            {formatDateTime(payment.paid_at)}
                                            {payment.creator
                                                ? ` · ${payment.creator}`
                                                : ''}
                                        </span>
                                    </span>
                                    <span
                                        className={cn(
                                            'tabular-nums',
                                            payment.amount < 0 &&
                                                'text-destructive',
                                        )}
                                    >
                                        {formatNumber(payment.amount)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Block>

                {!cancelled && (
                    <Block
                        done={order.status === 'completed'}
                        title={deliveryTitle(order)}
                        action={
                            <div className="flex flex-wrap gap-2">
                                {!active &&
                                    shipping.defaults &&
                                    shipping.canCreate &&
                                    order.delivery_method !== 'pickup' && (
                                        <Button
                                            variant="outline"
                                            onClick={() => setShipping0(true)}
                                        >
                                            Giao hàng
                                        </Button>
                                    )}
                                {!active?.integrated && (
                                    <DeliveryActions
                                        order={order}
                                        step={step}
                                    />
                                )}
                            </div>
                        }
                    >
                        {shipments.length > 0 && (
                            <div className="mb-4 grid gap-3">
                                {shipments.map((item) => (
                                    <ShipmentCard
                                        key={item.id}
                                        shipment={item}
                                    />
                                ))}
                            </div>
                        )}
                        <div className="grid gap-1 text-sm">
                            <span>Hình thức: {order.delivery_label}</span>
                            {order.carrier_name && (
                                <span>
                                    Đối tác: {order.carrier_name}
                                    {order.tracking_code
                                        ? ` · Mã vận đơn ${order.tracking_code}`
                                        : ''}
                                </span>
                            )}
                            {order.delivery_status && (
                                <span>
                                    Trạng thái giao hàng:{' '}
                                    <StatusPill
                                        statuses={deliveryStatuses}
                                        value={order.delivery_status}
                                    />
                                </span>
                            )}
                        </div>
                    </Block>
                )}

                {order.returns.length > 0 && (
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b px-5 py-4">
                            <CardTitle>Đơn trả hàng</CardTitle>
                        </CardHeader>
                        <ul className="divide-y text-sm">
                            {order.returns.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex justify-between gap-3 px-5 py-3"
                                >
                                    <span>
                                        <Link
                                            href={`/returns/${item.id}`}
                                            className="text-primary hover:underline"
                                        >
                                            {item.code}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {formatNumber(item.quantity)} sản
                                            phẩm ·{' '}
                                            {formatDateTime(item.created_at)}
                                        </span>
                                    </span>
                                    <span className="tabular-nums">
                                        {formatNumber(item.total)}
                                        <span className="block text-xs text-muted-foreground">
                                            Đã hoàn{' '}
                                            {formatNumber(item.refunded)}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </DocumentView>

            <PaymentDialog
                open={paying}
                onOpenChange={setPaying}
                url={`/orders/${order.id}/pay`}
                remaining={order.remaining}
                methods={paymentMethods}
                defaultMethod={defaultMethod}
                qr={order.qr}
            />
            {shipping.defaults && (
                <ShipmentDialog
                    open={shipping0}
                    onOpenChange={setShipping0}
                    orderId={order.id}
                    options={shipping}
                    defaults={shipping.defaults}
                />
            )}
            <CancelDialog
                open={cancelling}
                onOpenChange={setCancelling}
                order={order}
                reasons={cancelReasons}
                required={requireCancelReason}
            />
        </>
    );
}

/** One shipment of the order, like Sapo's "Đóng gói và giao hàng" block. */
function ShipmentCard({ shipment }: { shipment: ShipmentSummary }) {
    const can = useCan();
    const cancelled = shipment.status === 'cancelled';

    return (
        <div
            className={cn(
                'rounded-md border text-sm',
                cancelled && 'opacity-70',
            )}
        >
            <div className="flex flex-wrap items-center gap-2 border-b px-4 py-2.5">
                <Link
                    href={`/shipments/${shipment.id}`}
                    className="font-medium text-primary hover:underline"
                >
                    {shipment.code}
                </Link>
                <StatusPill
                    statuses={shipmentStatuses}
                    value={shipment.status}
                />
                {shipment.cod_amount > 0 && !cancelled && (
                    <StatusPill
                        statuses={codStatuses}
                        value={shipment.cod_status}
                    />
                )}
                <span className="ml-auto flex flex-wrap gap-2">
                    {!cancelled && (
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={`/shipments/${shipment.id}/${shipment.integrated ? 'label' : 'print'}`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Printer /> In phiếu giao
                            </a>
                        </Button>
                    )}
                    {shipment.integrated &&
                        ACTIVE_SHIPMENT.includes(shipment.status) && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/shipments/${shipment.id}/sync`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Cập nhật trạng thái
                            </Button>
                        )}
                    {shipment.status === 'pending' &&
                        can('shipments.cancel') && (
                            <ConfirmAction
                                url={`/shipments/${shipment.id}/cancel`}
                                title="Hủy giao hàng?"
                                description={
                                    shipment.integrated
                                        ? 'Vận đơn được hủy trên hệ thống của hãng vận chuyển, đơn hàng quay lại bước Chờ đóng gói.'
                                        : 'Phiếu giao hàng bị hủy, đơn hàng quay lại bước Chờ đóng gói.'
                                }
                                confirmLabel="Hủy giao hàng"
                                destructive
                                trigger={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="text-destructive"
                                    >
                                        Hủy giao hàng
                                    </Button>
                                }
                            />
                        )}
                </span>
            </div>
            <div className="grid gap-x-6 gap-y-1.5 px-4 py-3 sm:grid-cols-2">
                <Line label="Vận chuyển bởi">{shipment.carrier}</Line>
                <Line label="Mã vận đơn">{shipment.tracking_code ?? '—'}</Line>
                {shipment.carrier_status && (
                    <Line label="Trạng thái đối tác">
                        {shipment.carrier_status}
                    </Line>
                )}
                {shipment.service_name && (
                    <Line label="Gói cước">{shipment.service_name}</Line>
                )}
                <Line label="Khối lượng">
                    {formatNumber(shipment.weight)} g
                </Line>
                {shipment.size && (
                    <Line label="Kích thước">{shipment.size}</Line>
                )}
                <Line label="Tiền thu hộ COD">
                    {formatNumber(shipment.cod_amount)}
                </Line>
                <Line label="Phí trả ĐTVC">{formatNumber(shipment.fee)}</Line>
                <Line label="Người trả phí">{shipment.fee_payer}</Line>
                {shipment.required_note && (
                    <Line label="Yêu cầu">{shipment.required_note}</Line>
                )}
                {shipment.note && <Line label="Ghi chú">{shipment.note}</Line>}
            </div>
        </div>
    );
}

function deliveryTitle(order: Order) {
    if (order.status === 'completed') {
        return order.delivery_method === 'pickup'
            ? 'Khách đã nhận hàng'
            : 'Giao hàng thành công';
    }

    if (order.status === 'shipped') {
        return order.delivery_status === 'returning'
            ? 'Giao hàng thất bại - chờ nhận lại hàng'
            : 'Đơn hàng đang giao';
    }

    return order.status === 'pending'
        ? 'Đơn hàng chờ duyệt'
        : order.status === 'approved'
          ? 'Chờ đóng gói'
          : 'Chờ xuất kho';
}

function DeliveryActions({
    order,
    step,
}: {
    order: Order;
    step: (action: string) => string;
}) {
    const can = useCan();
    const post = (action: string) =>
        router.post(step(action), {}, { preserveScroll: true });

    if (order.status === 'pending') {
        return can('orders.approve') ? (
            <Button onClick={() => post('approve')}>Duyệt đơn hàng</Button>
        ) : null;
    }

    if (!can('orders.ship')) {
        return null;
    }

    const exportButton = (
        <ConfirmAction
            url={step('export')}
            title="Xuất kho đơn hàng?"
            description="Hàng được trừ khỏi kho và ghi công nợ cho khách. Đơn đã xuất kho không sửa được, muốn đổi phải tạo đơn trả hàng."
            confirmLabel="Xuất kho"
            trigger={
                <Button>
                    {order.delivery_method === 'pickup'
                        ? 'Xuất kho & giao hàng'
                        : 'Xuất kho'}
                </Button>
            }
        />
    );

    if (order.status === 'approved') {
        return (
            <div className="flex gap-2">
                <Button variant="outline" onClick={() => post('pack')}>
                    Đóng gói
                </Button>
                {exportButton}
            </div>
        );
    }

    if (order.status === 'packed') {
        return exportButton;
    }

    if (order.status === 'shipped' && order.delivery_status === 'shipping') {
        return (
            <div className="flex gap-2">
                <ConfirmAction
                    url={step('delivery-failed')}
                    title="Giao hàng thất bại?"
                    description="Đơn chuyển sang Hủy giao - chờ nhận. Khi hàng về kho, bấm Nhận lại hàng."
                    confirmLabel="Giao thất bại"
                    destructive
                    trigger={<Button variant="outline">Giao thất bại</Button>}
                />
                <Button onClick={() => post('deliver')}>
                    Giao hàng thành công
                </Button>
            </div>
        );
    }

    if (order.status === 'shipped' && order.delivery_status === 'returning') {
        return (
            <ConfirmAction
                url={step('receive-back')}
                title="Nhận lại hàng?"
                description="Hàng được nhập lại kho và giữ cho đơn; công nợ của lần xuất được ghi giảm. Sau đó có thể giao lại hoặc hủy đơn."
                confirmLabel="Nhận lại hàng"
                trigger={<Button>Nhận lại hàng</Button>}
            />
        );
    }

    return null;
}

function StepBar({ order }: { order: Order }) {
    const current =
        order.status === 'cancelled' ? -1 : ORDER.indexOf(order.status);

    return (
        <Card className="px-5 py-4">
            <ol className="grid grid-cols-5">
                {STEPS.map(([key, label], index) => {
                    const done =
                        order.steps[key] !== null &&
                        (current >= index || order.status === 'cancelled');
                    const reached = current >= index;

                    return (
                        <li
                            key={key}
                            className="relative flex flex-col items-center gap-1 text-center text-xs"
                        >
                            {index > 0 && (
                                <span
                                    className={cn(
                                        'absolute top-3 right-1/2 h-0.5 w-full -translate-y-1/2',
                                        reached ? 'bg-primary' : 'bg-border',
                                    )}
                                />
                            )}
                            <span
                                className={cn(
                                    'relative z-10 flex size-6 items-center justify-center rounded-full border-2 bg-card',
                                    reached
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border',
                                )}
                            >
                                {reached && <Check className="size-3.5" />}
                            </span>
                            <span
                                className={cn(
                                    'font-medium',
                                    reached
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {label}
                            </span>
                            <span className="text-muted-foreground">
                                {done ? formatDateTime(order.steps[key]) : ''}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </Card>
    );
}

function Block({
    done,
    title,
    action,
    children,
}: {
    done: boolean;
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="gap-0 py-0">
            <div className="flex flex-wrap items-center gap-3 border-b px-5 py-3">
                <span
                    className={cn(
                        'flex size-6 items-center justify-center rounded-full',
                        done
                            ? 'bg-success text-white'
                            : 'border-2 border-warning',
                    )}
                >
                    {done && <Check className="size-3.5" />}
                </span>
                <span className="font-semibold">{title}</span>
                <span className="ml-auto">{action}</span>
            </div>
            <div className="px-5 py-4">{children}</div>
        </Card>
    );
}

function Line({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{children}</span>
        </div>
    );
}

function Sum({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: number;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex justify-between gap-3',
                strong && 'font-semibold',
            )}
        >
            <span>{label}</span>
            <span className="tabular-nums">{formatNumber(value)}</span>
        </div>
    );
}

function CancelDialog({
    open,
    onOpenChange,
    order,
    reasons,
    required,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: Order;
    reasons: { id: number; name: string }[];
    required: boolean;
}) {
    const form = useForm({ reason_id: null as number | null, note: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/orders/${order.id}/cancel`, {
                            preserveScroll: true,
                            onSuccess: () => onOpenChange(false),
                        });
                    }}
                >
                    <div>
                        <DialogTitle>Hủy đơn hàng {order.code}?</DialogTitle>
                        <DialogDescription className="mt-1">
                            {['shipped'].includes(order.status)
                                ? 'Hàng đã xuất sẽ được nhập lại kho. '
                                : 'Hàng đang giữ cho đơn được nhả. '}
                            {order.paid > 0
                                ? `Số tiền đã thu ${formatNumber(order.paid)} đ được ghi hoàn lại cho khách.`
                                : ''}
                        </DialogDescription>
                    </div>
                    <Field
                        label={required ? 'Lý do hủy *' : 'Lý do hủy'}
                        error={errors.reason_id ?? errors.reason}
                    >
                        <OptionSelect
                            value={
                                form.data.reason_id
                                    ? String(form.data.reason_id)
                                    : null
                            }
                            onChange={(value) =>
                                form.setData(
                                    'reason_id',
                                    value ? Number(value) : null,
                                )
                            }
                            options={toSelectOptions(reasons)}
                            emptyLabel={required ? undefined : 'Không chọn'}
                            placeholder="Chọn lý do"
                        />
                    </Field>
                    <Field label="Ghi chú" error={errors.note}>
                        <Input
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </Field>
                    {errors.status && (
                        <p className="text-sm text-destructive">
                            {errors.status}
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Thoát
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={
                                form.processing ||
                                (required && !form.data.reason_id)
                            }
                        >
                            Hủy đơn hàng
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

OrderShow.layout = {
    breadcrumbs: [
        { title: 'Danh sách đơn hàng', href: '/orders' },
        { title: 'Chi tiết đơn hàng', href: '#' },
    ],
};
