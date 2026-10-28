import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type {
    BulkAction,
    Column,
    FilterDef,
    TableData,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatDateTime } from '@/lib/format';

type Member = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_owner: boolean;
    status: string;
    last_login_at: string | null;
    created_at: string | null;
    roles: string[];
    branches: string[];
};

type Option = { id: number; name: string };

const statuses = [
    { value: 'active', label: 'Đang hoạt động' },
    { value: 'inactive', label: 'Ngừng hoạt động' },
];

const columns: Column<Member>[] = [
    {
        key: 'name',
        header: 'Nhân viên',
        sort: 'name',
        fixed: true,
        cell: (member) => (
            <>
                <div className="font-medium">
                    {member.name}
                    {member.is_owner && (
                        <Badge className="ml-2">Chủ cửa hàng</Badge>
                    )}
                </div>
                <div className="text-xs text-muted-foreground">
                    {member.email}
                </div>
            </>
        ),
    },
    { key: 'phone', header: 'Điện thoại', cell: (member) => member.phone },
    {
        key: 'roles',
        header: 'Vai trò',
        cell: (member) => member.roles.join(', '),
    },
    {
        key: 'branches',
        header: 'Chi nhánh',
        className: 'max-w-48 truncate',
        cell: (member) =>
            member.is_owner ? 'Tất cả' : member.branches.join(', '),
    },
    {
        key: 'last_login_at',
        header: 'Đăng nhập gần nhất',
        sort: 'last_login_at',
        className: 'whitespace-nowrap',
        cell: (member) => formatDateTime(member.last_login_at),
    },
    {
        key: 'created_at',
        header: 'Ngày tạo',
        sort: 'created_at',
        hidden: true,
        className: 'whitespace-nowrap',
        cell: (member) => formatDateTime(member.created_at),
    },
    {
        key: 'status',
        header: 'Trạng thái',
        cell: (member) => <StatusBadge status={member.status} />,
    },
];

export default function StaffIndex({
    staff,
    roles,
    branches,
}: {
    staff: TableData<Member>;
    roles: Option[];
    branches: Option[];
}) {
    const can = useCan();

    const filters: FilterDef[] = [
        {
            key: 'status',
            label: 'Trạng thái',
            type: 'select',
            options: statuses,
        },
        {
            key: 'role',
            label: 'Vai trò',
            type: 'select',
            options: roles.map((role) => ({
                value: String(role.id),
                label: role.name,
            })),
        },
        {
            key: 'branch',
            label: 'Chi nhánh',
            type: 'select',
            options: branches.map((branch) => ({
                value: String(branch.id),
                label: branch.name,
            })),
        },
        {
            key: 'last_login_at',
            label: 'Đăng nhập gần nhất',
            type: 'date-range',
        },
    ];

    const bulk = (action: string) => (ids: number[]) =>
        router.post(
            '/staff/bulk',
            { action, ids },
            { preserveScroll: true, preserveState: true },
        );

    const bulkActions: BulkAction[] = can('staff.update')
        ? [
              { label: 'Cho hoạt động', run: bulk('activate') },
              {
                  label: 'Ngừng hoạt động',
                  run: bulk('deactivate'),
                  confirm:
                      'Nhân viên ngừng hoạt động sẽ bị đăng xuất và không đăng nhập được nữa.',
              },
              ...(can('staff.delete')
                  ? [
                        {
                            label: 'Xóa',
                            run: bulk('delete'),
                            destructive: true,
                            confirm:
                                'Xóa các nhân viên đã chọn? Chủ cửa hàng và tài khoản của bạn sẽ được bỏ qua.',
                        },
                    ]
                  : []),
          ]
        : [];

    return (
        <>
            <Head title="Nhân viên" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Nhân viên"
                    description="Tài khoản đăng nhập, vai trò và chi nhánh làm việc của từng người."
                    actions={
                        can('staff.create') && (
                            <Button asChild>
                                <Link href="/staff/create">
                                    <Plus /> Thêm nhân viên
                                </Link>
                            </Button>
                        )
                    }
                />
                <DataTable
                    tableKey="staff"
                    data={staff}
                    columns={columns}
                    searchPlaceholder="Tìm theo tên, email, số điện thoại"
                    tabs={{ filter: 'status', options: statuses }}
                    filters={filters}
                    bulkActions={bulkActions}
                    canSelect={(member) => !member.is_owner}
                    exportable
                    empty="Không có nhân viên nào."
                    rowActions={(member) => (
                        <>
                            {can('staff.update') && (
                                <Button variant="ghost" size="icon" asChild>
                                    <Link
                                        href={`/staff/${member.id}/edit`}
                                        aria-label="Sửa"
                                    >
                                        <Pencil className="size-4" />
                                    </Link>
                                </Button>
                            )}
                            {can('staff.delete') && !member.is_owner && (
                                <ConfirmDelete
                                    url={`/staff/${member.id}`}
                                    title={`Xóa nhân viên ${member.name}?`}
                                    description="Nếu chỉ tạm nghỉ, hãy chuyển trạng thái sang Ngừng hoạt động thay vì xóa."
                                />
                            )}
                        </>
                    )}
                />
            </div>
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [{ title: 'Nhân viên', href: '/staff' }],
};
