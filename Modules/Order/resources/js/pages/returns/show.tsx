import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { DocumentView } from '@/components/app/document-view';
import { Pill, StatusPill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { PaymentDialog } from '../../components/payment-dialog';
import type { PaymentMethodOption } from '../../components/payment-dialog';
import { refundStatuses } from '../../components/statuses';

type OrderReturn = {
    id: number;
    code: string;
    order: { id: number; code: string };
    customer: {
        id: number;
        name: string;
        code: string;
        phone: string | null;
    } | null;
    branch: string;
    status: string;
    refund_status: string;
    reason: string | null;
    note: string | null;
    quantity: number;
    total: number;
    refunded: number;
    can_refund: number;
    creator: string | null;
    received_at: string | null;
    created_at: string | null;
    items: {
        id: number;
        name: string;
        sku: string;
        unit: string | null;
        quantity: number;
        price: number;
        total: number;
    }[];
    refunds: { id: number; method: string; amount: number; paid_at: string }[];
};

export default function ReturnShow({
    orderReturn,
    paymentMethods,
    defaultMethod,
}: {
    orderReturn: OrderReturn;
    paymentMethods: PaymentMethodOption[];
    defaultMethod: number | string | null;
}) {
    const can = useCan();
    const [refunding, setRefunding] = useState(false);
    const item = orderReturn;

    return (
        <>
            <Head title={`Đơn trả hàng ${item.code}`} />
            <DocumentView
                title={item.code}
                badges={
                    <>
                        <Pill tone="success">Đã nhận hàng</Pill>
                        <StatusPill
                            statuses={refundStatuses}
                            value={item.refund_status}
                        />
                    </>
                }
                meta={`${formatDateTime(item.created_at)} · ${item.branch}${item.creator ? ` · ${item.creator}` : ''}`}
                backHref="/returns"
                backLabel="Quay lại danh sách đơn trả hàng"
                actions={
                    can('returns.pay') &&
                    item.can_refund > 0 && (
                        <Button onClick={() => setRefunding(true)}>
                            Hoàn tiền
                        </Button>
                    )
                }
                aside={
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b px-5 py-4">
                            <CardTitle>Thông tin đơn trả</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 px-5 py-4 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Đơn hàng gốc
                                </span>
                                <Link
                                    href={`/orders/${item.order.id}`}
                                    className="text-primary hover:underline"
                                >
                                    {item.order.code}
                                </Link>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Khách hàng
                                </span>
                                {item.customer ? (
                                    <Link
                                        href={`/customers/${item.customer.id}`}
                                        className="text-primary hover:underline"
                                    >
                                        {item.customer.name}
                                    </Link>
                                ) : (
                                    <span>Khách lẻ</span>
                                )}
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Lý do
                                </span>
                                <span>{item.reason ?? '—'}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Nhận hàng
                                </span>
                                <span>{formatDateTime(item.received_at)}</span>
                            </div>
                            {item.note && (
                                <p className="whitespace-pre-line text-muted-foreground">
                                    {item.note}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                }
            >
                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle>Sản phẩm trả lại</CardTitle>
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
                                        Giá hoàn
                                    </th>
                                    <th className="py-2 text-right font-normal">
                                        Thành tiền
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {item.items.map((line) => (
                                    <tr
                                        key={line.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-3">
                                            {line.name}
                                            <div className="text-xs text-muted-foreground">
                                                {line.sku}
                                            </div>
                                        </td>
                                        <td className="py-3 text-right tabular-nums">
                                            {formatNumber(line.quantity)}
                                        </td>
                                        <td className="py-3 text-right tabular-nums">
                                            {formatNumber(line.price)}
                                        </td>
                                        <td className="py-3 text-right tabular-nums">
                                            {formatNumber(line.total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="grid gap-1.5 border-t px-5 py-4 text-sm sm:ml-auto sm:w-80">
                        <div className="flex justify-between font-semibold">
                            <span>Tổng giá trị hàng trả</span>
                            <span className="tabular-nums">
                                {formatNumber(item.total)}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span>Đã hoàn tiền</span>
                            <span className="tabular-nums">
                                {formatNumber(item.refunded)}
                            </span>
                        </div>
                    </div>
                </Card>
                {item.refunds.length > 0 && (
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b px-5 py-4">
                            <CardTitle>Lịch sử hoàn tiền</CardTitle>
                        </CardHeader>
                        <ul className="divide-y text-sm">
                            {item.refunds.map((refund) => (
                                <li
                                    key={refund.id}
                                    className="flex justify-between px-5 py-3"
                                >
                                    <span>
                                        {refund.method}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {formatDateTime(refund.paid_at)}
                                        </span>
                                    </span>
                                    <span className="tabular-nums">
                                        {formatNumber(refund.amount)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </DocumentView>
            <PaymentDialog
                open={refunding}
                onOpenChange={setRefunding}
                url={`/returns/${item.id}/refund`}
                remaining={item.can_refund}
                methods={paymentMethods}
                defaultMethod={defaultMethod}
                qr={null}
                title="Hoàn tiền cho khách"
                hint="Có thể hoàn tối đa"
                confirmLabel="Hoàn tiền"
            />
        </>
    );
}

ReturnShow.layout = {
    breadcrumbs: [
        { title: 'Khách trả hàng', href: '/returns' },
        { title: 'Chi tiết đơn trả hàng', href: '#' },
    ],
};
