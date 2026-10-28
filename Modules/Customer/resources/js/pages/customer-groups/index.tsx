import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { Pill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { bpToPercent } from '@/lib/percent';

type Row = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price: string;
    discount_bp: number;
    is_default: boolean;
    customers_count: number;
    created_at: string | null;
};

export default function CustomerGroupsIndex({
    groups,
}: {
    groups: TableData<Row>;
}) {
    const can = useCan();

    const columns: Column<Row>[] = [
        {
            key: 'name',
            header: 'Tên nhóm',
            sort: 'name',
            fixed: true,
            cell: (row) => (
                <span className="inline-flex items-center gap-2">
                    {can('customers.update') ? (
                        <RecordLink href={`/customer-groups/${row.id}/edit`}>
                            {row.name}
                        </RecordLink>
                    ) : (
                        row.name
                    )}
                    {row.is_default && <Pill tone="info">Mặc định</Pill>}
                </span>
            ),
        },
        { key: 'code', header: 'Mã nhóm', cell: (row) => row.code },
        { key: 'price', header: 'Giá mặc định', cell: (row) => row.price },
        {
            key: 'discount',
            header: 'Chiết khấu',
            className: 'text-right tabular-nums',
            cell: (row) =>
                row.discount_bp > 0 ? `${bpToPercent(row.discount_bp)}%` : '—',
        },
        {
            key: 'customers_count',
            header: 'Số lượng khách',
            sort: 'customers_count',
            className: 'text-right tabular-nums',
            cell: (row) => formatNumber(row.customers_count),
        },
        {
            key: 'description',
            header: 'Mô tả',
            cell: (row) => row.description,
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
            <Head title="Nhóm khách hàng" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="customer-groups"
                    data={groups}
                    columns={columns}
                    searchPlaceholder="Tìm theo tên, mã nhóm"
                    empty="Chưa có nhóm khách hàng."
                    actions={
                        can('customers.update') && (
                            <Button asChild>
                                <Link href="/customer-groups/create">
                                    <Plus /> Thêm nhóm khách hàng
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

CustomerGroupsIndex.layout = {
    breadcrumbs: [
        { title: 'Khách hàng', href: '/customers' },
        { title: 'Nhóm khách hàng', href: '/customer-groups' },
    ],
};
