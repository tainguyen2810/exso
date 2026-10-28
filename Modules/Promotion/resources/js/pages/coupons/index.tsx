import { Head, Link } from '@inertiajs/react';
import { Plus, Settings } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { statusOptions } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatNumber } from '@/lib/format';
import {
    PromotionState,
    promotionStates,
} from '../../components/promotion-fields';

type Row = {
    id: number;
    code: string;
    name: string;
    value_label: string;
    state: string;
    starts_at: string;
    ends_at: string | null;
    coupons_count: number;
    used_count: number;
    created_at: string | null;
};

const batchStates = Object.fromEntries(
    Object.entries(promotionStates).filter(([key]) => key !== 'stopped'),
);

/** "Quản lý mã giảm giá": các đợt phát hành mã. */
export default function CouponsIndex({
    batches,
    enabled,
}: {
    batches: TableData<Row>;
    enabled: boolean;
}) {
    const can = useCan();

    const columns: Column<Row>[] = [
        {
            key: 'name',
            header: 'Đợt phát hành',
            sort: 'name',
            fixed: true,
            cell: (row) => (
                <span className="grid">
                    <RecordLink href={`/coupons/${row.id}`}>
                        {row.name}
                    </RecordLink>
                    <span className="text-xs text-muted-foreground">
                        {row.code}
                    </span>
                </span>
            ),
        },
        {
            key: 'value',
            header: 'Giá trị khuyến mại',
            cell: (row) => row.value_label,
        },
        {
            key: 'starts_at',
            header: 'Thời gian áp dụng',
            sort: 'starts_at',
            className: 'whitespace-nowrap',
            cell: (row) => (
                <>
                    {formatDate(row.starts_at)} –{' '}
                    {row.ends_at ? formatDate(row.ends_at) : 'Không thời hạn'}
                </>
            ),
        },
        {
            key: 'coupons_count',
            header: 'Số mã',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.coupons_count),
        },
        {
            key: 'used_count',
            header: 'Đã sử dụng',
            sort: 'used_count',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.used_count),
        },
        {
            key: 'state',
            header: 'Trạng thái',
            cell: (row) => <PromotionState value={row.state} />,
        },
    ];

    return (
        <>
            <Head title="Mã giảm giá" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                {!enabled && (
                    <div className="mb-4 rounded-md border border-warning-border bg-warning-soft px-4 py-3 text-sm">
                        Cửa hàng đang tắt mã giảm giá, nhân viên không nhập được
                        mã khi bán hàng.{' '}
                        <Link
                            href="/promotions/settings"
                            className="text-primary hover:underline"
                        >
                            Bật trong Cấu hình khuyến mại
                        </Link>
                    </div>
                )}
                <DataTable
                    tableKey="coupons"
                    data={batches}
                    columns={columns}
                    searchPlaceholder="Tìm kiếm theo mã, tên đợt phát hành"
                    empty="Chưa có đợt phát hành mã giảm giá."
                    tabs={{
                        filter: 'state',
                        options: statusOptions(batchStates),
                    }}
                    filters={[
                        {
                            key: 'starts_at',
                            label: 'Ngày bắt đầu',
                            type: 'date-range',
                        },
                    ]}
                    toolbar={
                        <Link
                            href="/promotions/settings"
                            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-primary"
                        >
                            <Settings className="size-4" /> Cấu hình khuyến mại
                        </Link>
                    }
                    actions={
                        can('coupons.create') && (
                            <Button asChild>
                                <Link href="/coupons/create">
                                    <Plus /> Tạo đợt phát hành
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

CouponsIndex.layout = {
    breadcrumbs: [
        { title: 'Khuyến mại', href: '/promotions' },
        { title: 'Quản lý mã giảm giá', href: '/coupons' },
    ],
};
