import { Head, Link } from '@inertiajs/react';
import { BadgePercent, Gift, Plus, Settings } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { statusOptions } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import {
    PromotionState,
    promotionStates,
} from '../../components/promotion-fields';

type Row = {
    id: number;
    code: string;
    name: string;
    kind: string;
    method_label: string;
    state: string;
    starts_at: string;
    ends_at: string | null;
    usage_limit: number | null;
    used_count: number;
    created_at: string | null;
};

/** "Quản lý khuyến mại": chương trình chiết khấu và tặng quà. */
export default function PromotionsIndex({
    promotions,
    methods,
    enabled,
}: {
    promotions: TableData<Row>;
    methods: { value: string; label: string }[];
    enabled: boolean;
}) {
    const can = useCan();

    const columns: Column<Row>[] = [
        {
            key: 'name',
            header: 'Chương trình khuyến mại',
            sort: 'name',
            fixed: true,
            cell: (row) => (
                <span className="grid">
                    <RecordLink href={`/promotions/${row.id}`}>
                        {row.name}
                    </RecordLink>
                    <span className="text-xs text-muted-foreground">
                        {row.code}
                    </span>
                </span>
            ),
        },
        {
            key: 'method',
            header: 'Hình thức',
            cell: (row) => (
                <span className="inline-flex items-center gap-1.5">
                    {row.kind === 'gift' ? (
                        <Gift className="size-4 text-muted-foreground" />
                    ) : (
                        <BadgePercent className="size-4 text-muted-foreground" />
                    )}
                    {row.method_label}
                </span>
            ),
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
            key: 'used_count',
            header: 'Đã áp dụng',
            sort: 'used_count',
            className: 'text-right tabular-nums',
            cell: (row) =>
                row.usage_limit
                    ? `${formatNumber(row.used_count)}/${formatNumber(row.usage_limit)}`
                    : formatNumber(row.used_count),
        },
        {
            key: 'state',
            header: 'Trạng thái',
            cell: (row) => <PromotionState value={row.state} />,
        },
        {
            key: 'created_at',
            header: 'Ngày tạo',
            sort: 'created_at',
            hidden: true,
            cell: (row) => formatDateTime(row.created_at),
        },
    ];

    return (
        <>
            <Head title="Khuyến mại" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                {!enabled && (
                    <div className="mb-4 rounded-md border border-warning-border bg-warning-soft px-4 py-3 text-sm">
                        Cửa hàng đang tắt áp dụng khuyến mại, các chương trình
                        sẽ không được áp dụng cho đơn hàng.{' '}
                        <Link
                            href="/promotions/settings"
                            className="text-primary hover:underline"
                        >
                            Bật trong Cấu hình khuyến mại
                        </Link>
                    </div>
                )}
                <DataTable
                    tableKey="promotions"
                    data={promotions}
                    columns={columns}
                    searchPlaceholder="Tìm kiếm theo mã, tên chương trình khuyến mại"
                    empty="Chưa có chương trình khuyến mại."
                    tabs={{
                        filter: 'state',
                        options: statusOptions(promotionStates),
                    }}
                    filters={[
                        {
                            key: 'kind',
                            label: 'Loại khuyến mại',
                            type: 'select',
                            options: [
                                { value: 'discount', label: 'Chiết khấu' },
                                { value: 'gift', label: 'Tặng quà' },
                            ],
                        },
                        {
                            key: 'method',
                            label: 'Hình thức khuyến mại',
                            type: 'select',
                            options: methods,
                        },
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
                        can('promotions.create') && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button>
                                        <Plus /> Tạo khuyến mại
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-72"
                                >
                                    <DropdownMenuItem asChild>
                                        <Link href="/promotions/create?type=discount">
                                            <BadgePercent />
                                            <span>
                                                <span className="block font-medium">
                                                    Chiết khấu
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    Giảm giá theo đơn, sản phẩm,
                                                    số lượng
                                                </span>
                                            </span>
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem asChild>
                                        <Link href="/promotions/create?type=gift">
                                            <Gift />
                                            <span>
                                                <span className="block font-medium">
                                                    Tặng quà
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    Tặng sản phẩm theo đơn hoặc
                                                    sản phẩm mua
                                                </span>
                                            </span>
                                        </Link>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )
                    }
                />
            </div>
        </>
    );
}

PromotionsIndex.layout = {
    breadcrumbs: [
        { title: 'Khuyến mại', href: '/promotions' },
        { title: 'Quản lý khuyến mại', href: '/promotions' },
    ],
};
