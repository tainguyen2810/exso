import { Head, Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { toSelectOptions } from '@/components/app/option-select';
import { StatusPill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import {
    orderStates,
    syncStatuses,
    toOptions,
} from '../../components/marketplace';

type Row = {
    id: number;
    external_id: string;
    shop: string;
    platform: string;
    platform_status: string | null;
    platform_status_label: string | null;
    state: string;
    sync_status: string;
    error: string | null;
    order_id: number | null;
    order_code: string | null;
    buyer: string | null;
    total: number;
    carrier: string | null;
    tracking_code: string | null;
    placed_at: string | null;
    synced_at: string | null;
};

/** Sàn TMĐT → Đơn hàng: platform orders and the EXSO order of each. */
export default function MarketplaceOrders({
    orders,
    shops,
}: {
    orders: TableData<Row>;
    shops: { id: number; name: string }[];
}) {
    const can = useCan();
    const editable = can('marketplaces.update');

    const columns: Column<Row>[] = [
        {
            key: 'external_id',
            header: 'Mã đơn sàn',
            fixed: true,
            className: 'whitespace-nowrap',
            cell: (row) => (
                <span className="font-medium text-primary">
                    {row.external_id}
                </span>
            ),
        },
        { key: 'shop', header: 'Gian hàng', cell: (row) => row.shop },
        {
            key: 'placed_at',
            header: 'Ngày tạo',
            sort: 'placed_at',
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.placed_at),
        },
        {
            key: 'state',
            header: 'Trạng thái trên sàn',
            cell: (row) => (
                <div>
                    <StatusPill statuses={orderStates} value={row.state} />
                    {row.platform_status_label && (
                        <div className="mt-1 text-xs text-muted-foreground">
                            {row.platform_status_label}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'order',
            header: 'Đơn hàng EXSO',
            className: 'whitespace-nowrap',
            cell: (row) =>
                row.order_id ? (
                    <Link
                        href={`/orders/${row.order_id}`}
                        className="text-primary hover:underline"
                    >
                        {row.order_code}
                    </Link>
                ) : (
                    '—'
                ),
        },
        {
            key: 'sync_status',
            header: 'Đồng bộ',
            cell: (row) => (
                <div className="max-w-72">
                    <StatusPill
                        statuses={syncStatuses}
                        value={row.sync_status}
                    />
                    {row.error && (
                        <div className="mt-1 text-xs text-destructive">
                            {row.error}
                        </div>
                    )}
                </div>
            ),
        },
        { key: 'buyer', header: 'Người mua', cell: (row) => row.buyer ?? '—' },
        {
            key: 'total',
            header: 'Tổng tiền',
            sort: 'total',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.total),
        },
        {
            key: 'carrier',
            header: 'Vận chuyển',
            cell: (row) => (
                <div>
                    <div>{row.carrier ?? '—'}</div>
                    {row.tracking_code && (
                        <div className="text-xs text-muted-foreground">
                            {row.tracking_code}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'synced_at',
            header: 'Đồng bộ lúc',
            sort: 'synced_at',
            hidden: true,
            className: 'whitespace-nowrap',
            cell: (row) => formatDateTime(row.synced_at),
        },
    ];

    return (
        <>
            <Head title="Đơn hàng sàn TMĐT" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="marketplace_orders"
                    data={orders}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã đơn sàn, mã đơn EXSO, người mua, mã vận đơn"
                    tabs={{ filter: 'state', options: toOptions(orderStates) }}
                    filters={[
                        {
                            key: 'placed_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                        {
                            key: 'shop_id',
                            label: 'Gian hàng',
                            type: 'select',
                            options: toSelectOptions(shops),
                        },
                        {
                            key: 'sync_status',
                            label: 'Trạng thái đồng bộ',
                            type: 'select',
                            options: toOptions(syncStatuses),
                        },
                    ]}
                    rowActions={
                        editable
                            ? (row) =>
                                  row.sync_status !== 'synced' ||
                                  !row.order_id ? (
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          title="Đồng bộ lại đơn này"
                                          onClick={() =>
                                              router.post(
                                                  `/marketplaces/orders/${row.id}/sync`,
                                                  {},
                                                  { preserveScroll: true },
                                              )
                                          }
                                      >
                                          <RefreshCw className="size-4" />
                                      </Button>
                                  ) : null
                            : undefined
                    }
                    actions={
                        editable && (
                            <Button
                                onClick={() =>
                                    router.post(
                                        '/marketplaces/orders/pull',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RefreshCw /> Cập nhật đơn hàng
                            </Button>
                        )
                    }
                    exportable={can('orders.export')}
                    empty="Chưa có đơn sàn nào. Đơn mới trên gian hàng đã kết nối tự về sau vài phút."
                />
            </div>
        </>
    );
}

MarketplaceOrders.layout = {
    breadcrumbs: [
        { title: 'Sàn TMĐT', href: '/marketplaces' },
        { title: 'Đơn hàng', href: '/marketplaces/orders' },
    ],
};
