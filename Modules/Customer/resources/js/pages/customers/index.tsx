import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/app/import-dialog';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
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
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    group: string | null;
    assignee: string | null;
    debt: number;
    total_spent: number;
    orders_count: number;
    last_order_at: string | null;
    status: string;
    created_at: string | null;
};

type Option = { id: number; name: string };

const statuses: FilterOption[] = [
    { value: 'active', label: 'Đang giao dịch' },
    { value: 'inactive', label: 'Ngừng giao dịch' },
];

const columns: Column<Row>[] = [
    {
        key: 'code',
        header: 'Mã khách hàng',
        sort: 'code',
        className: 'whitespace-nowrap',
        cell: (row) => row.code,
    },
    {
        key: 'name',
        header: 'Tên khách hàng',
        sort: 'name',
        fixed: true,
        cell: (row) => (
            <RecordLink href={`/customers/${row.id}`}>{row.name}</RecordLink>
        ),
    },
    { key: 'phone', header: 'Số điện thoại', cell: (row) => row.phone },
    { key: 'email', header: 'Email', hidden: true, cell: (row) => row.email },
    { key: 'group', header: 'Nhóm khách hàng', cell: (row) => row.group },
    {
        key: 'debt',
        header: 'Nợ hiện tại',
        sort: 'debt',
        className: 'text-right tabular-nums',
        cell: (row) => (
            <span className={cn(row.debt > 0 && 'text-destructive')}>
                {formatNumber(row.debt)}
            </span>
        ),
    },
    {
        key: 'total_spent',
        header: 'Tổng chi tiêu',
        sort: 'total_spent',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.total_spent),
    },
    {
        key: 'orders_count',
        header: 'Tổng SL đơn hàng',
        sort: 'orders_count',
        className: 'text-right tabular-nums',
        cell: (row) => formatNumber(row.orders_count),
    },
    {
        key: 'last_order_at',
        header: 'Ngày mua cuối',
        sort: 'last_order_at',
        hidden: true,
        cell: (row) => formatDate(row.last_order_at),
    },
    {
        key: 'assignee',
        header: 'Nhân viên phụ trách',
        hidden: true,
        cell: (row) => row.assignee,
    },
    {
        key: 'status',
        header: 'Trạng thái',
        hidden: true,
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

export default function CustomersIndex({
    customers,
    groups,
    staff,
}: {
    customers: TableData<Row>;
    groups: Option[];
    staff: Option[];
}) {
    const can = useCan();
    const [groupIds, setGroupIds] = useState<number[] | null>(null);
    const [groupId, setGroupId] = useState<string | null>(null);

    const bulk =
        (action: string, extra: Record<string, unknown> = {}) =>
        (ids: number[]) =>
            router.post(
                '/customers/bulk',
                { action, ids, ...extra },
                { preserveScroll: true, preserveState: true },
            );

    const bulkActions: BulkAction[] = [
        ...(can('customers.update')
            ? [
                  { label: 'Chuyển nhóm khách hàng', run: setGroupIds },
                  { label: 'Đang giao dịch', run: bulk('active') },
                  { label: 'Ngừng giao dịch', run: bulk('inactive') },
              ]
            : []),
        ...(can('customers.delete')
            ? [
                  {
                      label: 'Xóa khách hàng',
                      run: bulk('delete'),
                      destructive: true,
                      confirm: 'Khách hàng còn công nợ sẽ được bỏ qua.',
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Khách hàng" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="customers"
                    data={customers}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã, tên, số điện thoại, email khách hàng"
                    tabs={{ filter: 'status', options: statuses }}
                    filters={[
                        {
                            key: 'group_id',
                            label: 'Nhóm khách hàng',
                            type: 'select',
                            options: toSelectOptions(groups),
                        },
                        {
                            key: 'debt',
                            label: 'Công nợ',
                            type: 'select',
                            options: [
                                { value: 'owing', label: 'Còn nợ' },
                                { value: 'credit', label: 'Khách trả trước' },
                                { value: 'none', label: 'Không nợ' },
                            ],
                        },
                        {
                            key: 'assigned_to',
                            label: 'Nhân viên phụ trách',
                            type: 'select',
                            options: toSelectOptions(staff),
                        },
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: statuses,
                        },
                        {
                            key: 'last_order_at',
                            label: 'Ngày mua cuối',
                            type: 'date-range',
                        },
                        {
                            key: 'created_at',
                            label: 'Ngày tạo',
                            type: 'date-range',
                        },
                    ]}
                    bulkActions={bulkActions}
                    exportable={can('customers.export')}
                    toolbar={
                        can('customers.import') && (
                            <ImportDialog
                                url="/customers/import"
                                templateUrl="/customers/import/template"
                                title="Nhập khách hàng từ Excel"
                                description="Khách có mã hoặc số điện thoại đã có sẽ được cập nhật, còn lại thêm mới. Có dòng lỗi thì không dòng nào được nhập."
                            />
                        )
                    }
                    empty="Chưa có khách hàng nào."
                    actions={
                        can('customers.create') && (
                            <Button asChild>
                                <Link href="/customers/create">
                                    <Plus /> Thêm khách hàng
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
            <Dialog
                open={groupIds !== null}
                onOpenChange={(open) => !open && setGroupIds(null)}
            >
                <DialogContent>
                    <DialogTitle>Chuyển nhóm khách hàng</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        {formatNumber(groupIds?.length ?? 0)} khách hàng được
                        chọn sẽ dùng giá và chiết khấu của nhóm mới cho các đơn
                        tạo sau.
                    </p>
                    <OptionSelect
                        value={groupId}
                        onChange={setGroupId}
                        options={toSelectOptions(groups)}
                        placeholder="Chọn nhóm khách hàng"
                    />
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setGroupIds(null)}
                        >
                            Hủy
                        </Button>
                        <Button
                            disabled={!groupId}
                            onClick={() => {
                                bulk('group', { group_id: Number(groupId) })(
                                    groupIds ?? [],
                                );
                                setGroupIds(null);
                            }}
                        >
                            Chuyển nhóm
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [{ title: 'Khách hàng', href: '/customers' }],
};
