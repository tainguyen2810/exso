import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type Log = {
    id: number;
    event: string;
    description: string;
    user: string | null;
    properties: {
        old?: Record<string, unknown>;
        new?: Record<string, unknown>;
    } | null;
    ip_address: string | null;
    created_at: string;
};

const events: Record<string, string> = {
    created: 'Thêm',
    updated: 'Sửa',
    deleted: 'Xóa',
    login: 'Đăng nhập',
};

const ALL = 'all';

function show(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Có' : 'Không';
    }

    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    return JSON.stringify(value);
}

export default function ActivityLogIndex({
    logs,
    users,
    filters,
}: {
    logs: Paginated<Log>;
    users: { id: number; name: string }[];
    filters: { user_id: number | null; event: string };
}) {
    const [expanded, setExpanded] = useState<number | null>(null);

    const filter = (key: string, value: string) =>
        router.get(
            '/activity-log',
            {
                user_id: filters.user_id ?? undefined,
                event: filters.event || undefined,
                [key]: value === ALL ? undefined : value,
            },
            { preserveState: true },
        );

    return (
        <>
            <Head title="Nhật ký hoạt động" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Nhật ký hoạt động"
                    description="Ai đã làm gì, lúc nào. Bấm vào một dòng để xem giá trị trước và sau khi sửa."
                />
                <Card className="gap-0 py-0">
                    <div className="flex flex-wrap gap-2 p-3">
                        <Select
                            value={
                                filters.user_id ? String(filters.user_id) : ALL
                            }
                            onValueChange={(value) => filter('user_id', value)}
                        >
                            <SelectTrigger className="w-52">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    Tất cả nhân viên
                                </SelectItem>
                                {users.map((user) => (
                                    <SelectItem
                                        key={user.id}
                                        value={String(user.id)}
                                    >
                                        {user.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.event || ALL}
                            onValueChange={(value) => filter('event', value)}
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    Tất cả thao tác
                                </SelectItem>
                                {Object.entries(events).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-40">
                                    Thời gian
                                </TableHead>
                                <TableHead>Nhân viên</TableHead>
                                <TableHead>Thao tác</TableHead>
                                <TableHead>Nội dung</TableHead>
                                <TableHead>IP</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.data.map((log) => (
                                <Fragment key={log.id}>
                                    <TableRow
                                        className="cursor-pointer"
                                        onClick={() =>
                                            setExpanded(
                                                expanded === log.id
                                                    ? null
                                                    : log.id,
                                            )
                                        }
                                    >
                                        <TableCell className="whitespace-nowrap">
                                            {formatDateTime(log.created_at)}
                                        </TableCell>
                                        <TableCell>
                                            {log.user ?? 'Hệ thống'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {events[log.event] ?? log.event}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{log.description}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {log.ip_address}
                                        </TableCell>
                                    </TableRow>
                                    {expanded === log.id && log.properties && (
                                        <TableRow className="bg-muted/30 hover:bg-muted/30">
                                            <TableCell colSpan={5}>
                                                <table className="text-xs">
                                                    <thead>
                                                        <tr className="text-muted-foreground">
                                                            <th className="pr-6 text-left font-medium">
                                                                Trường
                                                            </th>
                                                            <th className="pr-6 text-left font-medium">
                                                                Trước
                                                            </th>
                                                            <th className="text-left font-medium">
                                                                Sau
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {Object.keys({
                                                            ...log.properties
                                                                .old,
                                                            ...log.properties
                                                                .new,
                                                        }).map((field) => (
                                                            <tr key={field}>
                                                                <td className="pr-6 font-mono">
                                                                    {field}
                                                                </td>
                                                                <td className="pr-6">
                                                                    {show(
                                                                        log
                                                                            .properties
                                                                            ?.old?.[
                                                                            field
                                                                        ],
                                                                    )}
                                                                </td>
                                                                <td>
                                                                    {show(
                                                                        log
                                                                            .properties
                                                                            ?.new?.[
                                                                            field
                                                                        ],
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </Fragment>
                            ))}
                            {logs.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Chưa có hoạt động nào.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                    <Pagination page={logs} />
                </Card>
            </div>
        </>
    );
}

ActivityLogIndex.layout = {
    breadcrumbs: [{ title: 'Nhật ký hoạt động', href: '/activity-log' }],
};
