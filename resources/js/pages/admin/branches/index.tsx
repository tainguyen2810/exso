import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ImportDialog } from '@/components/app/import-dialog';
import { RecordLink } from '@/components/app/record-link';
import { Pill, StatusBadge } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { BulkAction, Column, TableData } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';

type Branch = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    address: string | null;
    is_default: boolean;
    status: string;
    users_count: number;
};

const statuses = [
    { value: 'active', label: 'Đang hoạt động' },
    { value: 'inactive', label: 'Ngừng hoạt động' },
];

const columns: Column<Branch>[] = [
    {
        key: 'code',
        header: 'Mã',
        sort: 'code',
        fixed: true,
        cell: (branch) => (
            <RecordLink
                href={`/branches/${branch.id}/edit`}
                permission="branches.update"
            >
                {branch.code}
            </RecordLink>
        ),
    },
    {
        key: 'name',
        header: 'Tên chi nhánh',
        sort: 'name',
        cell: (branch) => (
            <>
                {branch.name}
                {branch.is_default && (
                    <Pill tone="info" className="ml-2">
                        Mặc định
                    </Pill>
                )}
            </>
        ),
    },
    { key: 'phone', header: 'Điện thoại', cell: (branch) => branch.phone },
    {
        key: 'address',
        header: 'Địa chỉ',
        className: 'max-w-64 truncate',
        cell: (branch) => branch.address,
    },
    {
        key: 'users_count',
        header: 'Nhân viên',
        sort: 'users_count',
        className: 'text-right',
        cell: (branch) => formatNumber(branch.users_count),
    },
    {
        key: 'status',
        header: 'Trạng thái',
        cell: (branch) => <StatusBadge status={branch.status} />,
    },
];

export default function BranchesIndex({
    branches,
}: {
    branches: TableData<Branch>;
}) {
    const can = useCan();

    const bulk = (action: string) => (ids: number[]) =>
        router.post(
            '/branches/bulk',
            { action, ids },
            { preserveScroll: true, preserveState: true },
        );

    const bulkActions: BulkAction[] = can('branches.update')
        ? [
              { label: 'Cho hoạt động', run: bulk('activate') },
              {
                  label: 'Ngừng hoạt động',
                  run: bulk('deactivate'),
                  confirm:
                      'Chi nhánh mặc định sẽ được bỏ qua. Nhân viên không chọn được chi nhánh ngừng hoạt động.',
              },
          ]
        : [];

    return (
        <>
            <Head title="Chi nhánh" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="branches"
                    data={branches}
                    columns={columns}
                    searchPlaceholder="Tìm theo mã, tên, địa chỉ"
                    tabs={{ filter: 'status', options: statuses }}
                    filters={[
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: statuses,
                        },
                    ]}
                    bulkActions={bulkActions}
                    exportable
                    toolbar={
                        can('branches.create') && (
                            <ImportDialog
                                url="/branches/import"
                                templateUrl="/branches/import/template"
                                title="Nhập chi nhánh từ Excel"
                                description="Mã đã có sẽ được cập nhật, mã mới sẽ được thêm. Nếu có dòng lỗi thì không dòng nào được nhập."
                            />
                        )
                    }
                    actions={
                        can('branches.create') && (
                            <Button asChild>
                                <Link href="/branches/create">
                                    <Plus /> Thêm chi nhánh
                                </Link>
                            </Button>
                        )
                    }
                    empty="Không có chi nhánh nào."
                />
            </div>
        </>
    );
}

BranchesIndex.layout = {
    breadcrumbs: [{ title: 'Chi nhánh', href: '/branches' }],
};
