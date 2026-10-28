import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { PageHeader } from '@/components/app/page-header';
import { RecordLink } from '@/components/app/record-link';
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
import { formatDate, formatNumber } from '@/lib/format';

type Role = {
    id: number;
    name: string;
    description: string | null;
    permissions_count: number;
    active_users_count: number;
    inactive_users_count: number;
    created_at: string | null;
    updated_at: string | null;
};

export default function RolesIndex({
    roles,
    permissionTotal,
}: {
    roles: Role[];
    permissionTotal: number;
}) {
    const can = useCan();

    return (
        <>
            <Head title="Vai trò & phân quyền" />
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <PageHeader
                    title="Vai trò & phân quyền"
                    actions={
                        can('roles.create') && (
                            <Button asChild>
                                <Link href="/roles/create">
                                    <Plus /> Thêm vai trò
                                </Link>
                            </Button>
                        )
                    }
                />
                <Card className="gap-0 overflow-hidden py-0">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>Vai trò</TableHead>
                                <TableHead className="text-right">
                                    Số quyền
                                </TableHead>
                                <TableHead className="text-right">
                                    NV đang làm việc
                                </TableHead>
                                <TableHead className="text-right">
                                    NV đã nghỉ
                                </TableHead>
                                <TableHead>Ngày tạo</TableHead>
                                <TableHead>Cập nhật cuối</TableHead>
                                <TableHead>Ghi chú</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roles.map((role) => (
                                <TableRow key={role.id}>
                                    <TableCell>
                                        <RecordLink
                                            href={`/roles/${role.id}/edit`}
                                            permission="roles.update"
                                        >
                                            {role.name}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {role.permissions_count}/
                                        {permissionTotal}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(role.active_users_count)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(
                                            role.inactive_users_count,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(role.created_at)}
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(role.updated_at)}
                                    </TableCell>
                                    <TableCell className="max-w-64 truncate text-muted-foreground">
                                        {role.description}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </div>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [{ title: 'Vai trò & phân quyền', href: '/roles' }],
};
