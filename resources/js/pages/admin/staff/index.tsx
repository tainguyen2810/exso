import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SearchInput } from '@/components/app/search-input';
import { StatusBadge } from '@/components/app/status-badge';
import { Badge } from '@/components/ui/badge';
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
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

type Member = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_owner: boolean;
    status: string;
    last_login_at: string | null;
    roles: string[];
    branches: string[];
};

const tabs = [
    { value: '', label: 'Tất cả' },
    { value: 'active', label: 'Đang hoạt động' },
    { value: 'inactive', label: 'Ngừng hoạt động' },
];

export default function StaffIndex({
    staff,
    filters,
}: {
    staff: Paginated<Member>;
    filters: { search: string; status: string };
}) {
    const can = useCan();

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
                <Card className="gap-0 py-0">
                    <div className="flex gap-4 border-b px-3">
                        {tabs.map((tab) => (
                            <button
                                key={tab.value}
                                type="button"
                                onClick={() =>
                                    router.get(
                                        '/staff',
                                        {
                                            search: filters.search || undefined,
                                            status: tab.value || undefined,
                                        },
                                        { preserveState: true },
                                    )
                                }
                                className={cn(
                                    '-mb-px border-b-2 border-transparent py-2.5 text-sm text-muted-foreground',
                                    filters.status === tab.value &&
                                        'border-primary font-medium text-foreground',
                                )}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                    <div className="p-3">
                        <SearchInput
                            value={filters.search}
                            placeholder="Tìm theo tên, email, số điện thoại"
                        />
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nhân viên</TableHead>
                                <TableHead>Điện thoại</TableHead>
                                <TableHead>Vai trò</TableHead>
                                <TableHead>Chi nhánh</TableHead>
                                <TableHead>Đăng nhập gần nhất</TableHead>
                                <TableHead>Trạng thái</TableHead>
                                <TableHead className="w-24" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {staff.data.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>
                                        <div className="font-medium">
                                            {member.name}
                                            {member.is_owner && (
                                                <Badge className="ml-2">
                                                    Chủ cửa hàng
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {member.email}
                                        </div>
                                    </TableCell>
                                    <TableCell>{member.phone}</TableCell>
                                    <TableCell>
                                        {member.roles.join(', ')}
                                    </TableCell>
                                    <TableCell className="max-w-48 truncate">
                                        {member.is_owner
                                            ? 'Tất cả'
                                            : member.branches.join(', ')}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {formatDateTime(member.last_login_at)}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={member.status} />
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap">
                                        {can('staff.update') && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <Link
                                                    href={`/staff/${member.id}/edit`}
                                                    aria-label="Sửa"
                                                >
                                                    <Pencil className="size-4" />
                                                </Link>
                                            </Button>
                                        )}
                                        {can('staff.delete') &&
                                            !member.is_owner && (
                                                <ConfirmDelete
                                                    url={`/staff/${member.id}`}
                                                    title={`Xóa nhân viên ${member.name}?`}
                                                    description="Nếu chỉ tạm nghỉ, hãy chuyển trạng thái sang Ngừng hoạt động thay vì xóa."
                                                />
                                            )}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {staff.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Không có nhân viên nào.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                    <Pagination page={staff} />
                </Card>
            </div>
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [{ title: 'Nhân viên', href: '/staff' }],
};
