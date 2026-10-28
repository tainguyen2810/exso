import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { RecordLink } from '@/components/app/record-link';
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
                                        {role.users_count}
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
