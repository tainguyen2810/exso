import { Head, Link } from '@inertiajs/react';
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
import type { Paginated } from '@/types';

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

export default function BranchesIndex({
    branches,
    filters,
}: {
    branches: Paginated<Branch>;
    filters: { search: string };
}) {
    const can = useCan();

    return (
        <>
            <Head title="Chi nhánh" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Chi nhánh"
                    description="Cửa hàng, kho của bạn. Nhân viên chỉ làm việc ở chi nhánh được gán."
                    actions={
                        can('branches.create') && (
                            <Button asChild>
                                <Link href="/branches/create">
                                    <Plus /> Thêm chi nhánh
                                </Link>
                            </Button>
                        )
                    }
                />
                <Card className="gap-0 py-0">
                    <div className="p-3">
                        <SearchInput
                            value={filters.search}
                            placeholder="Tìm theo tên, mã chi nhánh"
                        />
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Mã</TableHead>
                                <TableHead>Tên chi nhánh</TableHead>
                                <TableHead>Điện thoại</TableHead>
                                <TableHead>Địa chỉ</TableHead>
                                <TableHead className="text-right">
                                    Nhân viên
                                </TableHead>
                                <TableHead>Trạng thái</TableHead>
                                <TableHead className="w-24" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {branches.data.map((branch) => (
                                <TableRow key={branch.id}>
                                    <TableCell className="font-medium">
                                        {branch.code}
                                    </TableCell>
                                    <TableCell>
                                        {branch.name}
                                        {branch.is_default && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Mặc định
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>{branch.phone}</TableCell>
                                    <TableCell className="max-w-64 truncate">
                                        {branch.address}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {branch.users_count}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={branch.status} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {can('branches.update') && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <Link
                                                    href={`/branches/${branch.id}/edit`}
                                                    aria-label="Sửa"
                                                >
                                                    <Pencil className="size-4" />
                                                </Link>
                                            </Button>
                                        )}
                                        {can('branches.delete') &&
                                            !branch.is_default && (
                                                <ConfirmDelete
                                                    url={`/branches/${branch.id}`}
                                                    title={`Xóa chi nhánh ${branch.name}?`}
                                                    description="Nhân viên đang gán chi nhánh này sẽ không còn làm việc ở đây."
                                                />
                                            )}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {branches.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Không có chi nhánh nào.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                    <Pagination page={branches} />
                </Card>
            </div>
        </>
    );
}

BranchesIndex.layout = {
    breadcrumbs: [{ title: 'Chi nhánh', href: '/branches' }],
};
