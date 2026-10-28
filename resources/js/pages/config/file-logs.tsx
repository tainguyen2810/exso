import { Head } from '@inertiajs/react';
import { Pill } from '@/components/app/status-badge';
import { DataTable } from '@/components/data-table';
import type { Column, TableData } from '@/components/data-table';
import { formatDateTime, formatNumber } from '@/lib/format';

type Log = {
    id: number;
    file_name: string;
    user: string | null;
    module: string;
    action: string;
    status: string;
    rows: number;
    message: string | null;
    created_at: string;
};

const actions: Record<string, string> = {
    export: 'Xuất file',
    import: 'Nhập file',
};

const tones = {
    done: 'success',
    failed: 'danger',
    processing: 'warning',
} as const;

/** Xuất/nhập file, like Sapo's log of Excel exports and imports. */
export default function FileLogs({
    logs,
    users,
    modules,
    statuses,
}: {
    logs: TableData<Log>;
    users: { id: number; name: string }[];
    modules: Record<string, string>;
    statuses: Record<string, string>;
}) {
    const columns: Column<Log>[] = [
        {
            key: 'file_name',
            header: 'Tên file',
            fixed: true,
            cell: (log) => (
                <span className="font-medium break-all">{log.file_name}</span>
            ),
        },
        {
            key: 'user',
            header: 'Người thao tác',
            cell: (log) => log.user ?? '—',
        },
        { key: 'module', header: 'Chức năng', cell: (log) => log.module },
        {
            key: 'action',
            header: 'Thao tác',
            cell: (log) => actions[log.action] ?? log.action,
        },
        {
            key: 'rows',
            header: 'Số dòng',
            className: 'text-right',
            cell: (log) => formatNumber(log.rows),
        },
        {
            key: 'created_at',
            header: 'Thời gian',
            sort: 'created_at',
            className: 'whitespace-nowrap',
            cell: (log) => formatDateTime(log.created_at),
        },
        {
            key: 'status',
            header: 'Trạng thái xử lý',
            cell: (log) => (
                <span title={log.message ?? undefined}>
                    <Pill
                        tone={
                            tones[log.status as keyof typeof tones] ?? 'neutral'
                        }
                    >
                        {statuses[log.status] ?? log.status}
                    </Pill>
                </span>
            ),
        },
        {
            key: 'message',
            header: 'Ghi chú',
            className: 'text-muted-foreground',
            cell: (log) => log.message,
        },
    ];

    return (
        <>
            <Head title="Xuất/nhập file" />
            <div className="px-4 pt-5 pb-10 md:px-8">
                <DataTable
                    tableKey="file-logs"
                    data={logs}
                    columns={columns}
                    searchPlaceholder="Tìm theo tên file"
                    filters={[
                        {
                            key: 'created_at',
                            label: 'Thời gian',
                            type: 'date-range',
                        },
                        {
                            key: 'user',
                            label: 'Người thao tác',
                            type: 'select',
                            options: users.map((user) => ({
                                value: String(user.id),
                                label: user.name,
                            })),
                        },
                        {
                            key: 'action',
                            label: 'Thao tác',
                            type: 'select',
                            options: Object.entries(actions).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        },
                        {
                            key: 'module',
                            label: 'Chức năng',
                            type: 'select',
                            options: Object.entries(modules).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        },
                        {
                            key: 'status',
                            label: 'Trạng thái',
                            type: 'select',
                            options: Object.entries(statuses).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        },
                    ]}
                    empty="Chưa có lần xuất, nhập file nào."
                />
            </div>
        </>
    );
}

FileLogs.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Xuất/nhập file', href: '/config/file-logs' },
    ],
};
