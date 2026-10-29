import { Head, Link, router, useForm } from '@inertiajs/react';
import { Printer, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { DocumentView } from '@/components/app/document-view';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { RecordDialog } from '@/components/app/settings-page';
import { StatusPill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import {
    ACTIVE,
    codStatuses,
    shipmentStatuses,
} from '../../components/statuses';

type Shipment = {
    id: number;
    code: string;
    tracking_code: string | null;
    status: string;
    carrier: string;
    integrated: boolean;
    carrier_status: string | null;
    service_name: string | null;
    cod_amount: number;
    fee: number;
    fee_payer: string;
    weight: number;
    size: string | null;
    required_note: string | null;
    note: string | null;
    cod_status: string;
    receiver_name: string;
    receiver_phone: string;
    address: string;
    created_at: string | null;
    order: {
        id: number;
        code: string;
        status: string;
        subtotal: number;
        discount: number;
        shipping_fee: number;
        total: number;
        items: {
            id: number;
            name: string;
            sku: string | null;
            unit: string | null;
            quantity: number;
            price: number;
            discount: number;
            total: number;
        }[];
    };
    branch: { name: string; address: string | null; phone: string | null };
    creator: string | null;
    expected_delivery_at: string | null;
    delivered_at: string | null;
    synced_at: string | null;
    reconciliation: { id: number; code: string } | null;
    cod_editable: boolean;
    next_statuses: string[];
    events: {
        id: number;
        status: string | null;
        note: string | null;
        creator: string | null;
        occurred_at: string;
    }[];
};

/** Chi tiết phiếu giao hàng: parcel, partner, COD, status history. */
export default function ShipmentShow({ shipment }: { shipment: Shipment }) {
    const can = useCan();
    const [editing, setEditing] = useState(false);
    const active = ACTIVE.includes(shipment.status);

    return (
        <>
            <Head title={`Phiếu giao hàng ${shipment.code}`} />
            <DocumentView
                title={shipment.code}
                badges={
                    <>
                        <StatusPill
                            statuses={shipmentStatuses}
                            value={shipment.status}
                        />
                        {shipment.cod_amount > 0 &&
                            shipment.status !== 'cancelled' && (
                                <StatusPill
                                    statuses={codStatuses}
                                    value={shipment.cod_status}
                                />
                            )}
                    </>
                }
                meta={`${formatDateTime(shipment.created_at)} · ${shipment.branch.name}${shipment.creator ? ` · ${shipment.creator}` : ''}`}
                backHref="/shipments"
                backLabel="Quay lại danh sách vận đơn"
                actions={
                    <>
                        {shipment.status !== 'cancelled' && (
                            <Button variant="outline" asChild>
                                <a
                                    href={`/shipments/${shipment.id}/${shipment.integrated ? 'label' : 'print'}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Printer /> In phiếu giao
                                </a>
                            </Button>
                        )}
                        {shipment.integrated && active && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/shipments/${shipment.id}/sync`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RefreshCw /> Cập nhật trạng thái
                            </Button>
                        )}
                        {can('shipments.update') && (
                            <Button
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                Sửa
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
                                            className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                        >
                                            Hủy giao hàng
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
                                <CardTitle>Người nhận</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-1 px-5 py-4 text-sm">
                                <span className="font-medium">
                                    {shipment.receiver_name}
                                </span>
                                <span>{shipment.receiver_phone}</span>
                                <span className="text-muted-foreground">
                                    {shipment.address}
                                </span>
                            </CardContent>
                        </Card>
                        <Card className="gap-0 py-0">
                            <CardHeader className="border-b px-5 py-4">
                                <CardTitle>Lịch sử giao hàng</CardTitle>
                            </CardHeader>
                            <CardContent className="px-5 py-4">
                                <ol className="relative grid gap-3 border-l pl-4 text-sm">
                                    {shipment.events.map((event) => (
                                        <li key={event.id} className="relative">
                                            <span className="absolute top-1.5 -left-[21px] size-2 rounded-full bg-primary" />
                                            <div className="font-medium">
                                                {event.status
                                                    ? shipmentStatuses[
                                                          event.status
                                                      ]?.[0]
                                                    : 'Ghi chú'}
                                            </div>
                                            {event.note && (
                                                <div className="text-muted-foreground">
                                                    {event.note}
                                                </div>
                                            )}
                                            <div className="text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    event.occurred_at,
                                                )}
                                                {event.creator
                                                    ? ` · ${event.creator}`
                                                    : ''}
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                                {shipment.synced_at && (
                                    <p className="mt-3 text-xs text-muted-foreground">
                                        Đồng bộ lần cuối{' '}
                                        {formatDateTime(shipment.synced_at)}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </>
                }
            >
                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle>Thông tin giao hàng</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-x-8 gap-y-2 px-5 py-4 text-sm sm:grid-cols-2">
                        <Line label="Đơn hàng">
                            <Link
                                href={`/orders/${shipment.order.id}`}
                                className="text-primary hover:underline"
                            >
                                {shipment.order.code}
                            </Link>
                        </Line>
                        <Line label="Vận chuyển bởi">{shipment.carrier}</Line>
                        <Line label="Mã vận đơn">
                            {shipment.tracking_code ?? '—'}
                        </Line>
                        {shipment.carrier_status && (
                            <Line label="Trạng thái đối tác">
                                {shipment.carrier_status}
                            </Line>
                        )}
                        {shipment.service_name && (
                            <Line label="Gói cước">
                                {shipment.service_name}
                            </Line>
                        )}
                        <Line label="Khối lượng">
                            {formatNumber(shipment.weight)} g
                        </Line>
                        {shipment.size && (
                            <Line label="Kích thước">{shipment.size}</Line>
                        )}
                        <Line label="Người trả phí">{shipment.fee_payer}</Line>
                        {shipment.required_note && (
                            <Line label="Yêu cầu khi giao">
                                {shipment.required_note}
                            </Line>
                        )}
                        {shipment.expected_delivery_at && (
                            <Line label="Dự kiến giao">
                                {formatDate(shipment.expected_delivery_at)}
                            </Line>
                        )}
                        {shipment.delivered_at && (
                            <Line label="Ngày giao hàng">
                                {formatDateTime(shipment.delivered_at)}
                            </Line>
                        )}
                        {shipment.note && (
                            <Line label="Ghi chú">{shipment.note}</Line>
                        )}
                    </CardContent>
                </Card>

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b px-5 py-4">
                        <CardTitle>Thu hộ và phí</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 px-5 py-4 text-sm sm:w-96">
                        <Line label="Tiền thu hộ COD">
                            {formatNumber(shipment.cod_amount)}
                        </Line>
                        <Line label="Phí trả ĐTVC">
                            {formatNumber(shipment.fee)}
                        </Line>
                        <Line label="Đối soát">
                            {shipment.reconciliation ? (
                                <Link
                                    href={`/cod-reconciliations/${shipment.reconciliation.id}`}
                                    className="text-primary hover:underline"
                                >
                                    {shipment.reconciliation.code}
                                </Link>
                            ) : (
                                <StatusPill
                                    statuses={codStatuses}
                                    value={shipment.cod_status}
                                />
                            )}
                        </Line>
                    </CardContent>
                </Card>

                {shipment.next_statuses.length > 0 &&
                    can('shipments.update') && (
                        <Card className="gap-3 px-5 py-4">
                            <CardTitle>Cập nhật trạng thái giao hàng</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Đối tác tự liên hệ: cập nhật trạng thái theo
                                thông báo của đối tác. Đơn hàng đi theo trạng
                                thái này.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {shipment.next_statuses
                                    .filter(
                                        (status) =>
                                            status !== 'cancelled' ||
                                            can('shipments.cancel'),
                                    )
                                    .map((status) => (
                                        <ConfirmAction
                                            key={status}
                                            onConfirm={() =>
                                                router.post(
                                                    `/shipments/${shipment.id}/status`,
                                                    { status },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            title={`Chuyển sang "${shipmentStatuses[status][0]}"?`}
                                            description={
                                                statusHelp[status] ?? ''
                                            }
                                            confirmLabel="Cập nhật"
                                            destructive={status === 'cancelled'}
                                            trigger={
                                                <Button variant="outline">
                                                    {
                                                        shipmentStatuses[
                                                            status
                                                        ][0]
                                                    }
                                                </Button>
                                            }
                                        />
                                    ))}
                            </div>
                        </Card>
                    )}

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
                                        Thành tiền
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {shipment.order.items.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2.5">
                                            {item.name}
                                            <div className="text-xs text-muted-foreground">
                                                {item.sku ?? 'Dịch vụ khác'}
                                                {item.unit
                                                    ? ` · ${item.unit}`
                                                    : ''}
                                            </div>
                                        </td>
                                        <td className="py-2.5 text-right tabular-nums">
                                            {formatNumber(item.quantity)}
                                        </td>
                                        <td className="py-2.5 text-right tabular-nums">
                                            {formatNumber(item.price)}
                                        </td>
                                        <td className="py-2.5 text-right tabular-nums">
                                            {formatNumber(item.total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="grid gap-1.5 border-t px-5 py-4 text-sm sm:ml-auto sm:w-80">
                        <Line label="Tổng tiền hàng">
                            {formatNumber(shipment.order.subtotal)}
                        </Line>
                        <Line label="Chiết khấu">
                            {formatNumber(shipment.order.discount)}
                        </Line>
                        <Line label="Phí giao hàng">
                            {formatNumber(shipment.order.shipping_fee)}
                        </Line>
                        <Line label="Khách phải trả">
                            <b>{formatNumber(shipment.order.total)}</b>
                        </Line>
                    </div>
                </Card>
            </DocumentView>

            <EditDialog
                open={editing}
                onOpenChange={setEditing}
                shipment={shipment}
            />
        </>
    );
}

const statusHelp: Record<string, string> = {
    shipping: 'Đối tác đã lấy hàng. Đơn hàng được xuất kho nếu chưa xuất.',
    delivered: 'Giao hàng thành công, đơn hàng hoàn thành và chờ đối soát COD.',
    redelivery: 'Giao không thành công, đối tác sẽ giao lại.',
    returning:
        'Đối tác đang hoàn hàng về, đơn hàng chuyển Hủy giao - chờ nhận.',
    returned: 'Hàng đã về cửa hàng: được nhập lại kho và giữ cho đơn.',
    cancelled: 'Hủy phiếu giao hàng, đơn hàng quay lại bước Chờ đóng gói.',
};

function EditDialog({
    open,
    onOpenChange,
    shipment,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shipment: Shipment;
}) {
    const form = useForm({
        note: shipment.note ?? '',
        tracking_code: shipment.tracking_code ?? '',
        cod_amount: shipment.cod_amount,
        fee: shipment.fee,
    });

    return (
        <RecordDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Sửa phiếu giao hàng ${shipment.code}`}
            processing={form.processing}
            onSubmit={() => {
                form.transform((values) =>
                    shipment.cod_editable ? values : { note: values.note },
                );
                form.put(`/shipments/${shipment.id}`, {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                });
            }}
        >
            {shipment.cod_editable && (
                <>
                    <Field label="Mã vận đơn" error={form.errors.tracking_code}>
                        <Input
                            value={form.data.tracking_code}
                            onChange={(event) =>
                                form.setData(
                                    'tracking_code',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field
                            label="Tiền thu hộ COD"
                            error={form.errors.cod_amount}
                        >
                            <MoneyInput
                                value={form.data.cod_amount}
                                onValueChange={(value) =>
                                    form.setData('cod_amount', value ?? 0)
                                }
                            />
                        </Field>
                        <Field label="Phí trả ĐTVC" error={form.errors.fee}>
                            <MoneyInput
                                value={form.data.fee}
                                onValueChange={(value) =>
                                    form.setData('fee', value ?? 0)
                                }
                            />
                        </Field>
                    </div>
                </>
            )}
            <Field label="Ghi chú" error={form.errors.note}>
                <Input
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
        </RecordDialog>
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

ShipmentShow.layout = {
    breadcrumbs: [
        { title: 'Quản lý vận đơn', href: '/shipments' },
        { title: 'Chi tiết vận đơn', href: '#' },
    ],
};
