import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { PageHeader } from '@/components/app/page-header';
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

type Role = {
    id: number;
    name: string;
    users_count: number;
    permissions_count: number;
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
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Vai trò & phân quyền"
                    description="Mỗi vai trò là một bộ quyền. Gán vai trò cho nhân viên ở trang Nhân viên."
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
                <Card className="gap-0 py-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Vai trò</TableHead>
                                <TableHead className="text-right">
                                    Số quyền
                                </TableHead>
                                <TableHead className="text-right">
                                    Nhân viên
                                </TableHead>
                                <TableHead className="w-24" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roles.map((role) => (
                                <TableRow key={role.id}>
                                    <TableCell className="font-medium">
                                        {role.name}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {role.permissions_count}/
                                        {permissionTotal}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {role.users_count}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {can('roles.update') && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <Link
                                                    href={`/roles/${role.id}/edit`}
                                                    aria-label="Sửa"
                                                >
                                                    <Pencil className="size-4" />
                                                </Link>
                                            </Button>
                                        )}
                                        {can('roles.delete') &&
                                            role.users_count === 0 && (
                                                <ConfirmDelete
                                                    url={`/roles/${role.id}`}
                                                    title={`Xóa vai trò ${role.name}?`}
                                                    description="Vai trò chưa được gán cho ai nên có thể xóa an toàn."
                                                />
                                            )}
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
