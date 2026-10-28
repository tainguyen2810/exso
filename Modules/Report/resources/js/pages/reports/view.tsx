import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, Save } from 'lucide-react';
import { useState } from 'react';
import { OptionSelect } from '@/components/app/option-select';
import type { SelectOption } from '@/components/app/option-select';
import { PeriodPicker } from '@/components/app/period-picker';
import { RecordDialog } from '@/components/app/settings-page';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ReportChart } from '../../components/report-chart';
import type { ChartData } from '../../components/report-chart';

type Column = {
    key: string;
    label: string;
    type: 'text' | 'number' | 'money' | 'percent' | 'date';
    link?: string;
};

type Row = Record<string, string | number | null>;

type ReportInfo = {
    key: string;
    group: string;
    group_title: string;
    title: string;
    description: string;
    url: string;
    filters: ('period' | 'branch' | 'source')[];
    options: { key: string; label: string; options: SelectOption[] }[];
};

type Saved = { id: number; name: string; url: string; is_shared: boolean };

type Query = Record<string, string | number>;

function cell(column: Column, value: Row[string]) {
    if (value === null || value === undefined || value === '') {
        return column.type === 'text' ? '—' : '';
    }

    if (column.type === 'percent') {
        return `${formatNumber(Number(value))}%`;
    }

    if (column.type === 'number' || column.type === 'money') {
        return formatNumber(Number(value));
    }

    return String(value);
}

/** One report: filters, chart, table with a "Tổng" row, Excel export. */
export default function ReportView({
    report,
    siblings,
    query,
    columns,
    rows,
    totals,
    pagination,
    chart,
    notice,
    branches,
    sources,
    saved,
}: {
    report: ReportInfo;
    siblings: { key: string; title: string; url: string }[];
    query: Query;
    columns: Column[];
    rows: Row[];
    totals: Row | null;
    pagination: {
        page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
    chart: ChartData | null;
    notice: string | null;
    branches: SelectOption[];
    sources: SelectOption[];
    saved: Saved[];
}) {
    const can = useCan();
    const [saving, setSaving] = useState(false);

    const visit = (
        changes: Record<string, string | null>,
        url = report.url,
    ) => {
        const next: Record<string, string> = {};

        for (const [key, value] of Object.entries({ ...query, ...changes })) {
            if (value !== null && value !== '' && key !== 'page') {
                next[key] = String(value);
            }
        }

        router.get(url, next, { preserveScroll: true, preserveState: true });
    };

    const page = (number: number) =>
        router.get(
            report.url,
            { ...query, page: number },
            { preserveScroll: true, preserveState: true },
        );

    const exportUrl = `${report.url}?${new URLSearchParams({
        ...Object.fromEntries(
            Object.entries(query).map(([key, value]) => [key, String(value)]),
        ),
        export: 'xlsx',
    })}`;

    const numeric = (column: Column) =>
        ['number', 'money', 'percent'].includes(column.type);

    return (
        <>
            <Head title={report.title} />
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div className="flex flex-wrap items-center gap-3">
                    {siblings.length > 1 && (
                        <OptionSelect
                            className="w-auto min-w-64 bg-background"
                            value={report.url}
                            onChange={(url) => url && visit({}, url)}
                            options={siblings.map((item) => ({
                                value: item.url,
                                label: item.title,
                            }))}
                        />
                    )}
                    {report.filters.includes('period') && (
                        <PeriodPicker
                            value={{
                                from: String(query.from ?? ''),
                                to: String(query.to ?? ''),
                            }}
                            onChange={(period) => visit(period)}
                        />
                    )}
                    {report.filters.includes('branch') &&
                        branches.length > 1 && (
                            <OptionSelect
                                className="w-auto min-w-44 bg-background"
                                value={
                                    query.branch_id
                                        ? String(query.branch_id)
                                        : null
                                }
                                onChange={(value) =>
                                    visit({ branch_id: value })
                                }
                                options={branches}
                                emptyLabel="Tất cả chi nhánh"
                            />
                        )}
                    {report.filters.includes('source') &&
                        sources.length > 0 && (
                            <OptionSelect
                                className="w-auto min-w-40 bg-background"
                                value={
                                    query.source_id
                                        ? String(query.source_id)
                                        : null
                                }
                                onChange={(value) =>
                                    visit({ source_id: value })
                                }
                                options={sources}
                                emptyLabel="Tất cả nguồn"
                            />
                        )}
                    {report.options.map((option) => (
                        <div
                            key={option.key}
                            className="flex items-center gap-2 text-sm"
                        >
                            <span className="text-muted-foreground">
                                {option.label}
                            </span>
                            <OptionSelect
                                className="w-auto min-w-32 bg-background"
                                value={String(query[option.key] ?? '')}
                                onChange={(value) =>
                                    visit({ [option.key]: value })
                                }
                                options={option.options}
                            />
                        </div>
                    ))}
                    <div className="ml-auto flex gap-2">
                        {can('reports.export') && (
                            <Button variant="outline" asChild>
                                <a href={exportUrl}>
                                    <Download /> Xuất báo cáo
                                </a>
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            onClick={() => setSaving(true)}
                        >
                            <Save /> Lưu báo cáo
                        </Button>
                    </div>
                </div>

                {saved.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Đã lưu:</span>
                        {saved.map((item) => (
                            <Link
                                key={item.id}
                                href={item.url}
                                className="rounded-full border bg-background px-3 py-1 hover:border-primary hover:text-primary"
                            >
                                {item.name}
                            </Link>
                        ))}
                    </div>
                )}

                {notice && (
                    <Card className="border-amber-300 bg-amber-50 px-5 py-3 text-sm text-amber-900">
                        {notice}
                    </Card>
                )}

                {chart && (
                    <Card className="gap-0 py-0">
                        <ReportChart data={chart} />
                    </Card>
                )}

                <Card className="gap-0 overflow-hidden py-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {columns.map((column, index) => (
                                    <TableHead
                                        key={column.key}
                                        className={cn(
                                            'whitespace-nowrap',
                                            numeric(column) && 'text-right',
                                            index === 0 && 'pl-5',
                                            index === columns.length - 1 &&
                                                'pr-5',
                                        )}
                                    >
                                        {column.label}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {totals && rows.length > 0 && (
                                <TableRow className="bg-muted/40 font-semibold">
                                    {columns.map((column, index) => (
                                        <TableCell
                                            key={column.key}
                                            className={cn(
                                                numeric(column) && 'text-right',
                                                index === 0 && 'pl-5',
                                                index === columns.length - 1 &&
                                                    'pr-5',
                                            )}
                                        >
                                            {index === 0
                                                ? 'Tổng'
                                                : cell(
                                                      column,
                                                      totals[column.key] ??
                                                          null,
                                                  )}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            )}
                            {rows.map((row, rowIndex) => (
                                <TableRow
                                    key={rowIndex}
                                    className={cn(
                                        row.level === 'section' &&
                                            'bg-muted/20 font-medium',
                                        row.level === 'total' &&
                                            'bg-primary/5 font-semibold',
                                    )}
                                >
                                    {columns.map((column, index) => {
                                        const url = column.link
                                            ? row[column.link]
                                            : null;
                                        const content = cell(
                                            column,
                                            row[column.key],
                                        );

                                        return (
                                            <TableCell
                                                key={column.key}
                                                className={cn(
                                                    numeric(column) &&
                                                        'text-right tabular-nums',
                                                    index === 0 && 'pl-5',
                                                    index === 0 &&
                                                        row.level === 'item' &&
                                                        'pl-9',
                                                    index === 0 &&
                                                        row.level === 'sub' &&
                                                        'pl-14 text-muted-foreground',
                                                    index ===
                                                        columns.length - 1 &&
                                                        'pr-5',
                                                    numeric(column) &&
                                                        Number(
                                                            row[column.key],
                                                        ) < 0 &&
                                                        'text-destructive',
                                                )}
                                            >
                                                {typeof url === 'string' &&
                                                url ? (
                                                    <Link
                                                        href={url}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {content}
                                                    </Link>
                                                ) : (
                                                    content
                                                )}
                                            </TableCell>
                                        );
                                    })}
                                </TableRow>
                            ))}
                            {rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={columns.length}
                                        className="py-12 text-center text-muted-foreground"
                                    >
                                        Không có dữ liệu trong khoảng thời gian
                                        này.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                    {pagination.last_page > 1 && (
                        <div className="flex items-center justify-between border-t px-5 py-3 text-sm">
                            <span className="text-muted-foreground">
                                {formatNumber(pagination.total)} dòng · Trang{' '}
                                {pagination.page}/{pagination.last_page}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.page <= 1}
                                    onClick={() => page(pagination.page - 1)}
                                >
                                    Trước
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        pagination.page >= pagination.last_page
                                    }
                                    onClick={() => page(pagination.page + 1)}
                                >
                                    Sau
                                </Button>
                            </div>
                        </div>
                    )}
                </Card>
                <p className="text-xs text-muted-foreground">
                    {report.description}
                </p>
            </div>
            {saving && (
                <SaveDialog
                    report={report.key}
                    query={query}
                    onClose={() => setSaving(false)}
                />
            )}
        </>
    );
}

function SaveDialog({
    report,
    query,
    onClose,
}: {
    report: string;
    query: Query;
    onClose: () => void;
}) {
    const form = useForm({
        name: '',
        report,
        query: Object.fromEntries(
            Object.entries(query).map(([key, value]) => [key, String(value)]),
        ),
        is_shared: false,
    });

    return (
        <RecordDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title="Lưu báo cáo tùy chỉnh"
            description="Lưu báo cáo với bộ lọc hiện tại để mở lại nhanh."
            onSubmit={() =>
                form.post('/reports/saved', {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={form.processing}
        >
            <div className="grid gap-2">
                <Label htmlFor="saved-name">Tên báo cáo *</Label>
                <Input
                    id="saved-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    maxLength={255}
                    autoFocus
                />
                <InputError message={form.errors.name} />
            </div>
            <label className="flex items-center gap-3 text-sm">
                <Checkbox
                    checked={form.data.is_shared}
                    onCheckedChange={(checked) =>
                        form.setData('is_shared', checked === true)
                    }
                />
                Dùng chung cho mọi nhân viên có quyền xem báo cáo
            </label>
        </RecordDialog>
    );
}

ReportView.layout = (props: { report: ReportInfo }) => ({
    breadcrumbs: [
        { title: 'Báo cáo', href: '/reports' },
        {
            title: props.report.group_title,
            href: `/reports/${props.report.group}`,
        },
        { title: props.report.title, href: props.report.url },
    ],
});
