import { Head, Link } from '@inertiajs/react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { FormSection } from '@/components/app/document-form';
import { DocumentLines, SummaryRow } from '@/components/app/document-lines';
import type { LineColumn, LineProduct } from '@/components/app/document-lines';
import { DocumentView } from '@/components/app/document-view';
import { Pill } from '@/components/app/status-badge';
import type { SupplierOption } from '@/components/app/supplier-picker';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    orderStatuses,
    receiptStatuses,
    receiveStatuses,
} from '../../components/statuses';

type Item = LineProduct & {
    id: number;
    quantity: number;
    received_quantity: number;
    price: number;
    discount: number;
    total: number;
};

type Order = {
    id: number;
    code: string;
    status: string;
    receive_status: string;
    supplier: SupplierOption;
    branch: string;
    expected_at: string | null;
    note: string | null;
    subtotal: number;
    discount: number;
    total: number;
    creator: string | null;
    created_at: string | null;
    items: Item[];
    receipts: {
        id: number;
        code: string;
        status: string;
        total: number;
        created_at: string | null;
    }[];
};

/** Purchase order detail: lines with received quantities and its receipts. */
export default function PurchaseOrderShow({ order }: { order: Order }) {
    const can = useCan();
    const pending = order.status === 'pending';
    const [statusLabel, statusTone] = orderStatuses[order.status] ?? [
        order.status,
    ];
    const [receiveLabel, receiveTone] = receiveStatuses[
        order.receive_status
    ] ?? [order.receive_status];
    const nothingReceived = order.receive_status === 'none';

    const columns: LineColumn<Item>[] = [
        {
            key: 'quantity',
            header: 'Số lượng',
            className: 'text-right tabular-nums',
            cell: (item) => formatNumber(item.quantity),
        },
        {
            key: 'received',
            header: 'Đã nhập',
            className: 'text-right tabular-nums',
            cell: (item) => (
                <span
                    className={cn(
                        item.received_quantity >= item.quantity
                            ? 'text-success'
                            : 'text-muted-foreground',
                    )}
                >
                    {formatNumber(item.received_quantity)}
                </span>
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
        {
            key: 'total',
            header: 'Thành tiền',
            className: 'text-right tabular-nums',
            cell: (item) => formatNumber(item.total),
        },
    ];

    return (
        <>
            <Head title={`Đơn đặt hàng ${order.code}`} />
            <DocumentView
                title={order.code}
                backHref="/purchase-orders"
                backLabel="Quay lại danh sách đặt hàng nhập"
                badges={
                    <>
                        <Pill tone={statusTone}>{statusLabel}</Pill>
                        <Pill tone={receiveTone}>{receiveLabel}</Pill>
                    </>
                }
                meta={`Tạo lúc ${formatDateTime(order.created_at)}${order.creator ? ` bởi ${order.creator}` : ''}`}
                actions={
                    pending && (
                        <>
                            {can('purchases.cancel') && (
                                <ConfirmAction
                                    url={`/purchase-orders/${order.id}/close`}
                                    title={
                                        nothingReceived
                                            ? `Hủy đơn ${order.code}?`
                                            : `Kết thúc đơn ${order.code}?`
                                    }
                                    description={
                                        nothingReceived
                                            ? 'Đơn chưa nhập hàng nên sẽ chuyển sang Đã hủy.'
                                            : 'Phần chưa nhập sẽ không nhập nữa, đơn chuyển sang Hoàn thành.'
                                    }
                                    confirmLabel={
                                        nothingReceived
                                            ? 'Hủy đơn'
                                            : 'Kết thúc đơn'
                                    }
                                    destructive={nothingReceived}
                                    trigger={
                                        <Button variant="outline">
                                            {nothingReceived
                                                ? 'Hủy đơn'
                                                : 'Kết thúc đơn'}
                                        </Button>
                                    }
                                />
                            )}
                            {nothingReceived && can('purchases.update') && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/purchase-orders/${order.id}/edit`}
                                    >
                                        Sửa đơn
                                    </Link>
                                </Button>
                            )}
                            {can('purchases.create') && (
                                <Button asChild>
                                    <Link
                                        href={`/purchase-receipts/create?order=${order.id}`}
                                    >
                                        Tạo phiếu nhập
                                    </Link>
                                </Button>
                            )}
                        </>
                    )
                }
                aside={
                    <>
                        <FormSection title="Thông tin đơn" className="gap-2">
                            <SummaryRow
                                label="Chi nhánh"
                                value={order.branch}
                            />
                            <SummaryRow
                                label="Ngày nhận dự kiến"
                                value={formatDate(order.expected_at) || '—'}
                            />
                        </FormSection>
                        <FormSection title="Ghi chú">
                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                {order.note || 'Chưa có ghi chú.'}
                            </p>
                        </FormSection>
                    </>
                }
            >
                <FormSection title="Nhà cung cấp" className="gap-1 text-sm">
                    <Link
                        href={`/suppliers/${order.supplier.id}`}
                        className="font-medium text-primary hover:underline"
                    >
                        {order.supplier.name}
                    </Link>
                    <span className="text-muted-foreground">
                        {order.supplier.code}
                        {order.supplier.phone
                            ? ` · ${order.supplier.phone}`
                            : ''}
                    </span>
                </FormSection>
                <DocumentLines
                    readOnly
                    lines={order.items}
                    columns={columns}
                    footer={
                        <div className="grid gap-2 border-t px-5 py-4 md:ml-auto md:w-96">
                            <SummaryRow
                                label="Tổng tiền hàng"
                                value={formatNumber(order.subtotal)}
                            />
                            <SummaryRow
                                label="Chiết khấu đơn"
                                value={formatNumber(order.discount)}
                            />
                            <SummaryRow
                                strong
                                label="Giá trị đơn"
                                value={formatNumber(order.total)}
                            />
                        </div>
                    }
                />
                <Card className="gap-0 overflow-hidden py-0">
                    <h3 className="border-b px-5 py-4 font-medium">
                        Phiếu nhập hàng
                    </h3>
                    {order.receipts.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            Chưa có phiếu nhập nào từ đơn này.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-5">
                                        Mã phiếu
                                    </TableHead>
                                    <TableHead>Ngày tạo</TableHead>
                                    <TableHead>Trạng thái</TableHead>
                                    <TableHead className="pr-5 text-right">
                                        Giá trị
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {order.receipts.map((receipt) => {
                                    const [label, tone] = receiptStatuses[
                                        receipt.status
                                    ] ?? [receipt.status];

                                    return (
                                        <TableRow key={receipt.id}>
                                            <TableCell className="pl-5">
                                                <Link
                                                    href={`/purchase-receipts/${receipt.id}`}
                                                    className="text-primary hover:underline"
                                                >
                                                    {receipt.code}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                {formatDateTime(
                                                    receipt.created_at,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Pill tone={tone}>{label}</Pill>
                                            </TableCell>
                                            <TableCell className="pr-5 text-right tabular-nums">
                                                {formatNumber(receipt.total)}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    )}
                </Card>
            </DocumentView>
        </>
    );
}

PurchaseOrderShow.layout = {
    breadcrumbs: [
        { title: 'Đặt hàng nhập', href: '/purchase-orders' },
        { title: 'Chi tiết đơn', href: '#' },
    ],
};
