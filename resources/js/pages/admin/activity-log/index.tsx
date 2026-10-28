import { Head } from '@inertiajs/react';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/format';

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

const columns: Column<Log>[] = [
    {
        key: 'created_at',
        header: 'Thời gian',
        sort: 'created_at',
        fixed: true,
        className: 'w-40 whitespace-nowrap',
        cell: (log) => formatDateTime(log.created_at),
    },
    { key: 'user', header: 'Nhân viên', cell: (log) => log.user ?? 'Hệ thống' },
    {
        key: 'event',
        header: 'Thao tác',
        cell: (log) => (
            <Badge variant="outline">{events[log.event] ?? log.event}</Badge>
        ),
    },
    { key: 'description', header: 'Nội dung', cell: (log) => log.description },
    {
        key: 'ip_address',
        header: 'IP',
        className: 'text-muted-foreground',
        cell: (log) => log.ip_address,
    },
];

function Changes({ log }: { log: Log }) {
    const fields = Object.keys({
        ...log.properties?.old,
        ...log.properties?.new,
    });

    if (fields.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                Không có thay đổi chi tiết.
            </p>
        );
    }

    return (
        <table className="text-xs">
            <thead>
                <tr className="text-muted-foreground">
                    <th className="pr-6 text-left font-medium">Trường</th>
                    <th className="pr-6 text-left font-medium">Trước</th>
                    <th className="text-left font-medium">Sau</th>
                </tr>
            </thead>
            <tbody>
                {fields.map((field) => (
                    <tr key={field}>
                        <td className="pr-6 font-mono">{field}</td>
                        <td className="pr-6">
                            {show(log.properties?.old?.[field])}
                        </td>
                        <td>{show(log.properties?.new?.[field])}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export default function ActivityLogIndex({
    logs,
    users,
}: {
    logs: TableData<Log>;
    users: { id: number; name: string }[];
}) {
    return (
        <>
            <Head title="Nhật ký hoạt động" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="activity-log"
                    data={logs}
                    columns={columns}
                    searchPlaceholder="Tìm theo nội dung, IP"
                    filters={[
                        {
                            key: 'user',
                            label: 'Nhân viên',
                            type: 'select',
                            options: users.map((user) => ({
                                value: String(user.id),
                                label: user.name,
                            })),
                        },
                        {
                            key: 'event',
                            label: 'Thao tác',
                            type: 'select',
                            options: Object.entries(events).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        },
                        {
                            key: 'created_at',
                            label: 'Thời gian',
                            type: 'date-range',
                        },
                    ]}
                    expand={(log) => <Changes log={log} />}
                    exportable
                    empty="Chưa có hoạt động nào."
                />
            </div>
        </>
    );
}

ActivityLogIndex.layout = {
    breadcrumbs: [{ title: 'Nhật ký hoạt động', href: '/activity-log' }],
};
