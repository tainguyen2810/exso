import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/app/field';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { RecordLink } from '@/components/app/record-link';
import { StatusPill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { BulkAction, Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    channels,
    deliveryStatuses,
    orderStatuses,
    orderStatusFilters,
    paymentStatuses,
    toOptions,
} from '../../components/statuses';

type Row = {
    id: number;
    code: string;
    channel: string;
    status: string;
    status_label: string;
    payment_status: string | null;
    delivery_status: string | null;
    customer_id: number | null;
    customer_name: string | null;
    customer_phone: string | null;
    branch: string;
    source: string | null;
    assignee: string | null;
    total: number;
    paid: number;
    sale_at: string | null;
    created_at: string | null;
    note: string | null;
    tags: string | null;
    lines: {
        id: number;
        name: string;
        sku: string | null;
        quantity: number;
        price: number;
        discount: number;
        total: number;
    }[];
};

type Option = { id: number; name: string };
type Queue = { key: string; label: string; count: number; amount: number };

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã đơn hàng',
        sort: 'code',
        fixed: true,
        className: 'whitespace-nowrap',
        cell: (row) => (
            <RecordLink href={`/orders/${row.id}`}>{row.code}</RecordLink>
        ),
    },
    {
        key: 'sale_at',
        header: 'Ngày tạo đơn',
        sort: 'sale_at',
        className: 'whitespace-nowrap',
        cell: (row) => formatDateTime(row.sale_at),
    },
    {
        key: 'customer',
        header: 'Tên khách hàng',
        cell: (row) =>
            row.customer_id ? (
                <Link
                    href={`/customers/${row.customer_id}`}
                    className="hover:text-primary"
                >
                    {row.customer_name}
                </Link>
            ) : (
                <span className="text-muted-foreground">
                    {row.customer_name ?? 'Khách lẻ'}
                </span>
            ),
    },
    {
        key: 'status',
        header: 'Trạng thái đơn hàng',
        cell: (row) => (
            <StatusPill statuses={orderStatuses} value={row.status} />
        ),
    },
    {
        key: 'payment',
        header: 'Trạng thái thanh toán',
        cell: (row) => (
            <StatusPill statuses={paymentStatuses} value={row.payment_status} />
        ),
    },
    {
        key: 'delivery',
        header: 'Trạng thái giao hàng',
        cell: (row) =>
            row.status === 'cancelled' ? null : row.delivery_status ? (
                <StatusPill
                    statuses={deliveryStatuses}
                    value={row.delivery_status}
                />
            ) : (
                <span className="text-xs text-muted-foreground">
                    Chưa giao hàng
                </span>
            ),
    },
    {
        key: 'total',
        header: 'Khách phải trả',
        sort: 'total',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.total),
    },
    {
        key: 'source',
        header: 'Nguồn đơn',
        cell: (row) => row.source ?? channels[row.channel],
    },
    {
        key: 'branch',
        header: 'Chi nhánh',
        hidden: true,
        cell: (row) => row.branch,
    },
    {
        key: 'assignee',
        header: 'Nhân viên bán',
        hidden: true,
        cell: (row) => row.assignee,
    },
    {
        key: 'phone',
        header: 'SĐT khách',
        hidden: true,
        cell: (row) => row.customer_phone,
    },
    { key: 'tags', header: 'Tag', hidden: true, cell: (row) => row.tags },
    { key: 'note', header: 'Ghi chú', hidden: true, cell: (row) => row.note },
];

/** Danh sách đơn hàng like Sapo: work queues on top, tabs, quick view. */
export default function OrdersIndex({
    orders,
    queues,
    branches,
    sources,
    staff,
    paymentMethods,
    cancelReasons,
    requireCancelReason,
}: {
    orders: TableData<Row>;
    queues: Queue[];
    branches: Option[];
    sources: Option[];
    staff: Option[];
    paymentMethods: Option[];
    cancelReasons: Option[];
    requireCancelReason: boolean;
}) {
    const can = useCan();
    const [dialog, setDialog] = useState<{
        action: 'pay' | 'cancel';
        ids: number[];
    } | null>(null);
    const [methodId, setMethodId] = useState<string | null>(
        paymentMethods[0] ? String(paymentMethods[0].id) : null,
    );
    const [reasonId, setReasonId] = useState<string | null>(null);
    const activeQueue = orders.query.filter.queue as string | undefined;

    const post = (
        action: string,
        ids: number[],
        extra: Record<string, unknown> = {},
    ) =>
        router.post(
            '/orders/bulk',
            { action, ids, ...extra },
            { preserveScroll: true, preserveState: true },
        );

    const bulkActions: BulkAction[] = [
        ...(can('orders.approve')
            ? [
                  {
                      label: 'Duyệt đơn hàng',
                      run: (ids: number[]) => post('approve', ids),
                  },
              ]
            : []),
        ...(can('orders.pay')
            ? [
                  {
                      label: 'Thanh toán đơn hàng',
                      run: (ids: number[]) => setDialog({ action: 'pay', ids }),
                  },
              ]
            : []),
        ...(can('orders.cancel')
            ? [
                  {
                      label: 'Hủy đơn hàng',
                      destructive: true,
                      run: (ids: number[]) =>
                          setDialog({ action: 'cancel', ids }),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Danh sách đơn hàng" />
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <Card className="gap-0 py-0">
                    <div className="border-b px-5 py-3 text-sm font-semibold">
                        Đơn hàng cần xử lý
                    </div>
                    <div className="grid grid-cols-2 divide-x divide-y sm:grid-cols-3 sm:divide-y-0 lg:grid-cols-6">
                        {queues.map((queue) => (
                            <Link
                                key={queue.key}
                                href={
                                    activeQueue === queue.key
                                        ? '/orders'
                                        : `/orders?filter[queue]=${queue.key}`
                                }
                                preserveScroll
                                className={cn(
                                    'grid gap-0.5 px-5 py-3 text-sm hover:bg-accent',
                                    activeQueue === queue.key && 'bg-accent',
                                )}
                            >
                                <span className="text-muted-foreground">
                                    {queue.label}
                                </span>
                                <span className="text-lg font-semibold text-primary tabular-nums">
                                    {formatNumber(queue.count)}
                                </span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {formatNumber(queue.amount)} đ
                                </span>
                            </Link>
                        ))}
                    </div>
                </Card>
                <DataTable
                    tableKey="orders"
                    data={orders}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã đơn hàng, tên, SĐT khách hàng, mã vận đơn"
                    tabs={{
                        filter: 'status',
                        options: [
                            { value: 'pending', label: 'Đặt hàng' },
                            { value: 'approved', label: 'Chờ đóng gói' },
                            { value: 'completed', label: 'Hoàn thành' },
                            { value: 'cancelled', label: 'Đã hủy' },
                        ],
                    }}
                    filters={[
                        {
                            key: 'status',
                            label: 'Trạng thái đơn hàng',
                            type: 'select',
                            options: orderStatusFilters,
                        },
                        {
                            key: 'payment',
                            label: 'Trạng thái thanh toán',
                            type: 'select',
                            options: toOptions(paymentStatuses),
                        },
                        {
                            key: 'queue',
                            label: 'Cần xử lý',
                            type: 'select',
                            options: queues.map((queue) => ({
                                value: queue.key,
                                label: queue.label,
                            })),
                        },
                        {
                            key: 'branch_id',
                            label: 'Chi nhánh',
                            type: 'select',
                            options: toSelectOptions(branches),
                        },
                        {
                            key: 'source_id',
                            label: 'Nguồn đơn',
                            type: 'select',
                            options: toSelectOptions(sources),
                        },
                        {
                            key: 'assigned_to',
                            label: 'Nhân viên bán',
                            type: 'select',
                            options: toSelectOptions(staff),
                        },
                        {
                            key: 'channel',
                            label: 'Kênh bán',
                            type: 'select',
                            options: Object.entries(channels).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        },
                        {
                            key: 'sale_at',
                            label: 'Ngày tạo đơn',
                            type: 'date-range',
                        },
                    ]}
                    bulkActions={bulkActions}
                    exportable={can('orders.export')}
                    expand={(row) => <QuickView row={row} />}
                    empty="Chưa có đơn hàng nào."
                    actions={
                        can('orders.create') && (
                            <Button asChild>
                                <Link href="/orders/create">
                                    <Plus /> Tạo đơn hàng
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
            <Dialog
                open={dialog !== null}
                onOpenChange={(open) => !open && setDialog(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {dialog?.action === 'pay'
                            ? 'Thanh toán đơn hàng'
                            : 'Hủy đơn hàng'}
                    </DialogTitle>
                    <DialogDescription>
                        {dialog?.action === 'pay'
                            ? `Ghi nhận khách đã trả hết số còn lại của ${dialog.ids.length} đơn hàng.`
                            : `Hủy ${dialog?.ids.length ?? 0} đơn hàng. Hàng đang giữ được nhả, tiền đã thu được hoàn lại.`}
                    </DialogDescription>
                    {dialog?.action === 'pay' ? (
                        <Field label="Hình thức thanh toán">
                            <OptionSelect
                                value={methodId}
                                onChange={setMethodId}
                                options={toSelectOptions(paymentMethods)}
                            />
                        </Field>
                    ) : (
                        <Field
                            label={
                                requireCancelReason
                                    ? 'Lý do hủy *'
                                    : 'Lý do hủy'
                            }
                        >
                            <OptionSelect
                                value={reasonId}
                                onChange={setReasonId}
                                options={toSelectOptions(cancelReasons)}
                                emptyLabel={
                                    requireCancelReason
                                        ? undefined
                                        : 'Không chọn'
                                }
                                placeholder="Chọn lý do"
                            />
                        </Field>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setDialog(null)}
                        >
                            Thoát
                        </Button>
                        <Button
                            variant={
                                dialog?.action === 'cancel'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={
                                dialog?.action === 'pay'
                                    ? !methodId
                                    : requireCancelReason && !reasonId
                            }
                            onClick={() => {
                                if (dialog) {
                                    post(
                                        dialog.action,
                                        dialog.ids,
                                        dialog.action === 'pay'
                                            ? {
                                                  payment_method_id:
                                                      Number(methodId),
                                              }
                                            : {
                                                  reason_id: reasonId
                                                      ? Number(reasonId)
                                                      : null,
                                              },
                                    );
                                }

                                setDialog(null);
                            }}
                        >
                            {dialog?.action === 'pay'
                                ? 'Thanh toán'
                                : 'Hủy đơn hàng'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function QuickView({ row }: { row: Row }) {
    return (
        <div className="grid gap-4 py-2 lg:grid-cols-3">
            <table className="text-sm lg:col-span-2">
                <thead className="text-left text-muted-foreground">
                    <tr>
                        <th className="pb-1 font-normal">Sản phẩm</th>
                        <th className="pb-1 text-right font-normal">SL</th>
                        <th className="pb-1 text-right font-normal">Đơn giá</th>
                        <th className="pb-1 text-right font-normal">
                            Thành tiền
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {row.lines.map((line) => (
                        <tr key={line.id}>
                            <td className="py-0.5">
                                {line.name}
                                {line.sku && (
                                    <span className="text-xs text-muted-foreground">
                                        {' '}
                                        · {line.sku}
                                    </span>
                                )}
                            </td>
                            <td className="py-0.5 text-right tabular-nums">
                                {formatNumber(line.quantity)}
                            </td>
                            <td className="py-0.5 text-right tabular-nums">
                                {formatNumber(line.price)}
                            </td>
                            <td className="py-0.5 text-right tabular-nums">
                                {formatNumber(line.total)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="grid content-start gap-1 text-sm">
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        Khách phải trả
                    </span>
                    <span className="tabular-nums">
                        {formatNumber(row.total)}
                    </span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">Đã thanh toán</span>
                    <span className="tabular-nums">
                        {formatNumber(row.paid)}
                    </span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">Chi nhánh</span>
                    <span>{row.branch}</span>
                </div>
                {row.note && (
                    <p className="text-muted-foreground">Ghi chú: {row.note}</p>
                )}
                <Link
                    href={`/orders/${row.id}`}
                    className="mt-1 text-primary hover:underline"
                >
                    Xem chi tiết đơn hàng
                </Link>
            </div>
        </div>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [{ title: 'Danh sách đơn hàng', href: '/orders' }],
};
