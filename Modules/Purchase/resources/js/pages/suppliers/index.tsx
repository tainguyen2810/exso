import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
import { Pill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type {
    BulkAction,
    Column,
    FilterOption,
    TableData,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    debt: number;
    status: string;
    created_at: string | null;
};

const statuses: FilterOption[] = [
    { value: 'active', label: 'Đang giao dịch' },
    { value: 'inactive', label: 'Ngừng giao dịch' },
];

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã nhà cung cấp',
        sort: 'code',
        className: 'whitespace-nowrap',
        cell: (row) => row.code,
    },
    {
        key: 'name',
        header: 'Tên nhà cung cấp',
        sort: 'name',
        fixed: true,
        cell: (row) => (
            <RecordLink href={`/suppliers/${row.id}`}>{row.name}</RecordLink>
        ),
    },
    { key: 'phone', header: 'Số điện thoại', cell: (row) => row.phone },
    { key: 'email', header: 'Email', hidden: true, cell: (row) => row.email },
    {
        key: 'debt',
        header: 'Công nợ hiện tại',
        sort: 'debt',
        className: 'text-right tabular-nums',
        cell: (row) => (
            <span className={cn(row.debt > 0 && 'text-destructive')}>
                {formatNumber(row.debt)}
            </span>
        ),
    },
    {
        key: 'status',
        header: 'Trạng thái',
        cell: (row) =>
            row.status === 'active' ? (
                <Pill tone="success">Đang giao dịch</Pill>
            ) : (
                <Pill>Ngừng giao dịch</Pill>
            ),
    },
    {
        key: 'created_at',
        header: 'Ngày tạo',
        sort: 'created_at',
        hidden: true,
        cell: (row) => formatDateTime(row.created_at),
    },
];

export default function SuppliersIndex({
    suppliers,
    totalDebt,
}: {
    suppliers: TableData<Row>;
    totalDebt: number;
}) {
    const can = useCan();

    const bulk = (action: string) => (ids: number[]) =>
        router.post(
            '/suppliers/bulk',
            { action, ids },
            { preserveScroll: true, preserveState: true },
        );

    const bulkActions: BulkAction[] = [
        ...(can('suppliers.update')
            ? [
                  { label: 'Đang giao dịch', run: bulk('activate') },
                  { label: 'Ngừng giao dịch', run: bulk('deactivate') },
              ]
            : []),
        ...(can('suppliers.delete')
            ? [
                  {
                      label: 'Xóa nhà cung cấp',
                      run: bulk('delete'),
                      destructive: true,
                      confirm:
                          'Nhà cung cấp đã có phiếu nhập hoặc công nợ sẽ được bỏ qua.',
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Nhà cung cấp" />
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <Card className="py-4">
                    <CardContent className="flex items-baseline gap-3 px-5">
                        <span className="text-sm text-muted-foreground">
                            Tổng công nợ phải trả nhà cung cấp
                        </span>
                        <span className="text-xl font-semibold text-destructive tabular-nums">
                            {formatNumber(totalDebt)} đ
                        </span>
                    </CardContent>
                </Card>
                <DataTable
                    tableKey="suppliers"
                    data={suppliers}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã, tên, số điện thoại, email, mã số thuế"
                    tabs={{ filter: 'status', options: statuses }}
                    filters={[
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: statuses,
                        },
                        {
                            key: 'debt',
                            label: 'Công nợ',
                            type: 'select',
                            options: [
                                { value: 'owing', label: 'Còn nợ' },
                                { value: 'none', label: 'Không nợ' },
                            ],
                        },
                        {
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                    ]}
                    bulkActions={bulkActions}
                    exportable={can('suppliers.export')}
                    empty="Chưa có nhà cung cấp nào."
                    actions={
                        can('suppliers.create') && (
                            <Button asChild>
                                <Link href="/suppliers/create">
                                    <Plus /> Thêm nhà cung cấp
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

SuppliersIndex.layout = {
    breadcrumbs: [{ title: 'Nhà cung cấp', href: '/suppliers' }],
};
