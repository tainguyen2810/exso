import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronDown,
    ChevronRight,
    Download,
    Search,
    X,
} from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
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
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ColumnChooser, useVisibleColumns } from './column-chooser';
import { describeFilter, FilterButton, FilterSheet } from './filter-sheet';
import { SavedViews } from './saved-views';
import type { BulkAction, Column, FilterDef, TableData, Tabs } from './types';
import { useTableQuery } from './use-table-query';

const PER_PAGE = [20, 50, 100];

type Props<T> = {
    /** Key for saved filters and remembered columns, e.g. "staff". */
    tableKey: string;
    data: TableData<T>;
    columns: Column<T>[];
    rowId?: (row: T) => number;
    searchPlaceholder?: string;
    filters?: FilterDef[];
    /** Quick tabs above the toolbar, bound to one select filter. */
    tabs?: Tabs;
    bulkActions?: BulkAction[];
    /** Rows that cannot be selected for bulk actions (e.g. the store owner). */
    canSelect?: (row: T) => boolean;
    rowActions?: (row: T) => ReactNode;
    /** Details shown when a row is expanded. */
    expand?: (row: T) => ReactNode;
    exportable?: boolean;
    /** Extra buttons in the toolbar, e.g. import. */
    toolbar?: ReactNode;
    empty?: string;
};

/**
 * Shared list for every index page: server paging, search, filter drawer
 * (F10), saved filters, column chooser, bulk actions and Excel export.
 * Pair with App\Support\DataTable\DataTable on the server.
 */
export function DataTable<T>({
    tableKey,
    data,
    columns,
    rowId = (row) => (row as { id: number }).id,
    searchPlaceholder = 'Tìm kiếm',
    filters = [],
    tabs,
    bulkActions = [],
    canSelect = () => true,
    rowActions,
    expand,
    exportable = false,
    toolbar,
    empty = 'Không có dữ liệu.',
}: Props<T>) {
    const { rows, query, views } = data;
    const navigate = useTableQuery(query);
    const { shown, visible, toggle, reset } = useVisibleColumns(
        tableKey,
        columns,
    );
    const [selected, setSelected] = useState<number[]>([]);
    const [expanded, setExpanded] = useState<number[]>([]);
    const [filterOpen, setFilterOpen] = useState(false);
    const [pending, setPending] = useState<BulkAction | null>(null);

    useEffect(() => setSelected([]), [rows]);

    useEffect(() => {
        if (filters.length === 0) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F10') {
                event.preventDefault();
                setFilterOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [filters.length]);

    const selectable = bulkActions.length > 0;
    const pageIds = rows.data.filter(canSelect).map(rowId);
    const allSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const activeFilters = filters.filter(
        (filter) =>
            query.filter[filter.key] !== undefined &&
            filter.key !== tabs?.filter,
    );
    const colSpan =
        shown.length +
        (selectable ? 1 : 0) +
        (expand ? 1 : 0) +
        (rowActions ? 1 : 0);

    const sortBy = (column: string) => {
        const current = query.sort.replace(/^-/, '');
        const desc = query.sort.startsWith('-');
        navigate({
            sort: current === column && !desc ? `-${column}` : column,
        });
    };

    const exportUrl = () => {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');
        params.set('export', 'xlsx');

        return `${window.location.pathname}?${params.toString()}`;
    };

    const run = (action: BulkAction) => {
        if (action.confirm) {
            setPending(action);
        } else {
            action.run(selected);
        }
    };

    return (
        <Card className="gap-0 overflow-hidden py-0">
            {tabs && (
                <div className="flex gap-4 overflow-x-auto border-b px-3">
                    {[{ value: '', label: 'Tất cả' }, ...tabs.options].map(
                        (tab) => (
                            <button
                                key={tab.value}
                                type="button"
                                onClick={() =>
                                    navigate({
                                        filter: {
                                            ...query.filter,
                                            [tabs.filter]:
                                                tab.value || undefined,
                                        },
                                    })
                                }
                                className={cn(
                                    '-mb-px border-b-2 border-transparent py-2.5 text-sm whitespace-nowrap text-muted-foreground hover:text-foreground',
                                    (query.filter[tabs.filter] ?? '') ===
                                        tab.value &&
                                        'border-primary font-medium text-foreground',
                                )}
                            >
                                {tab.label}
                            </button>
                        ),
                    )}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2 p-3">
                <SearchBox
                    value={query.search}
                    placeholder={searchPlaceholder}
                    onSearch={(search) => navigate({ search })}
                />
                {filters.length > 0 && (
                    <FilterButton
                        count={activeFilters.length}
                        onClick={() => setFilterOpen(true)}
                    />
                )}
                <SavedViews
                    tableKey={tableKey}
                    views={views}
                    query={query}
                    onApply={(view) =>
                        navigate({
                            search: view.query.search ?? '',
                            filter: view.query.filter ?? {},
                            sort: view.query.sort ?? '',
                        })
                    }
                />
                <div className="ml-auto flex items-center gap-2">
                    {toolbar}
                    {exportable && (
                        <Button variant="outline" asChild>
                            <a
                                href={exportUrl()}
                                title="Xuất danh sách đang lọc ra Excel"
                            >
                                <Download />
                                <span className="hidden sm:inline">
                                    Xuất Excel
                                </span>
                            </a>
                        </Button>
                    )}
                    <ColumnChooser
                        columns={columns}
                        visible={visible}
                        onToggle={toggle}
                        onReset={reset}
                    />
                </div>
            </div>

            {(activeFilters.length > 0 || query.search) && (
                <div className="flex flex-wrap items-center gap-2 px-3 pb-3">
                    {query.search && (
                        <Chip
                            label={`Từ khóa: ${query.search}`}
                            onRemove={() => navigate({ search: '' })}
                        />
                    )}
                    {activeFilters.map((filter) => (
                        <Chip
                            key={filter.key}
                            label={describeFilter(
                                filter,
                                query.filter[filter.key],
                            )}
                            onRemove={() =>
                                navigate({
                                    filter: {
                                        ...query.filter,
                                        [filter.key]: undefined,
                                    },
                                })
                            }
                        />
                    ))}
                    <button
                        type="button"
                        className="text-sm text-primary hover:underline"
                        onClick={() =>
                            navigate({
                                search: '',
                                filter: tabs
                                    ? {
                                          [tabs.filter]:
                                              query.filter[tabs.filter],
                                      }
                                    : {},
                            })
                        }
                    >
                        Xóa tất cả
                    </button>
                </div>
            )}

            {selected.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 border-y bg-primary/5 px-3 py-2 text-sm">
                    <span className="font-medium">
                        Đã chọn {formatNumber(selected.length)}
                    </span>
                    {bulkActions.map((action) => (
                        <Button
                            key={action.label}
                            size="sm"
                            variant={
                                action.destructive ? 'destructive' : 'outline'
                            }
                            onClick={() => run(action)}
                        >
                            {action.label}
                        </Button>
                    ))}
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setSelected([])}
                    >
                        Bỏ chọn
                    </Button>
                </div>
            )}

            <Table>
                <TableHeader className="bg-muted/50">
                    <TableRow>
                        {selectable && (
                            <TableHead className="w-10">
                                <Checkbox
                                    aria-label="Chọn tất cả trên trang"
                                    checked={allSelected}
                                    disabled={pageIds.length === 0}
                                    onCheckedChange={() =>
                                        setSelected(allSelected ? [] : pageIds)
                                    }
                                />
                            </TableHead>
                        )}
                        {expand && <TableHead className="w-8" />}
                        {shown.map((column) => (
                            <TableHead
                                key={column.key}
                                className={column.className}
                            >
                                {column.sort ? (
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 hover:text-foreground"
                                        onClick={() => sortBy(column.sort!)}
                                    >
                                        {column.header}
                                        <SortIcon
                                            sort={query.sort}
                                            column={column.sort}
                                        />
                                    </button>
                                ) : (
                                    column.header
                                )}
                            </TableHead>
                        ))}
                        {rowActions && <TableHead className="w-24" />}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.data.map((row) => {
                        const id = rowId(row);
                        const isOpen = expanded.includes(id);

                        return (
                            <Fragment key={id}>
                                <TableRow
                                    data-state={
                                        selected.includes(id)
                                            ? 'selected'
                                            : undefined
                                    }
                                >
                                    {selectable && (
                                        <TableCell>
                                            {canSelect(row) && (
                                                <Checkbox
                                                    aria-label="Chọn dòng"
                                                    checked={selected.includes(
                                                        id,
                                                    )}
                                                    onCheckedChange={() =>
                                                        setSelected(
                                                            (current) =>
                                                                current.includes(
                                                                    id,
                                                                )
                                                                    ? current.filter(
                                                                          (
                                                                              item,
                                                                          ) =>
                                                                              item !==
                                                                              id,
                                                                      )
                                                                    : [
                                                                          ...current,
                                                                          id,
                                                                      ],
                                                        )
                                                    }
                                                />
                                            )}
                                        </TableCell>
                                    )}
                                    {expand && (
                                        <TableCell className="px-1">
                                            <button
                                                type="button"
                                                aria-label="Xem chi tiết"
                                                className="rounded p-1 text-muted-foreground hover:bg-muted"
                                                onClick={() =>
                                                    setExpanded((current) =>
                                                        isOpen
                                                            ? current.filter(
                                                                  (item) =>
                                                                      item !==
                                                                      id,
                                                              )
                                                            : [...current, id],
                                                    )
                                                }
                                            >
                                                {isOpen ? (
                                                    <ChevronDown className="size-4" />
                                                ) : (
                                                    <ChevronRight className="size-4" />
                                                )}
                                            </button>
                                        </TableCell>
                                    )}
                                    {shown.map((column) => (
                                        <TableCell
                                            key={column.key}
                                            className={cn(
                                                'whitespace-nowrap',
                                                column.className,
                                            )}
                                        >
                                            {column.cell(row)}
                                        </TableCell>
                                    ))}
                                    {rowActions && (
                                        <TableCell className="text-right whitespace-nowrap">
                                            {rowActions(row)}
                                        </TableCell>
                                    )}
                                </TableRow>
                                {expand && isOpen && (
                                    <TableRow className="bg-muted/30 hover:bg-muted/30">
                                        <TableCell colSpan={colSpan}>
                                            {expand(row)}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </Fragment>
                        );
                    })}
                    {rows.data.length === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={colSpan}
                                className="py-12 text-center text-muted-foreground"
                            >
                                {empty}
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>

            <Footer
                data={data}
                onPerPage={(perPage) => navigate({ per_page: perPage })}
            />

            {filters.length > 0 && (
                <FilterSheet
                    filters={filters}
                    value={query.filter}
                    open={filterOpen}
                    onOpenChange={setFilterOpen}
                    onApply={(filter) => navigate({ filter })}
                />
            )}

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
            >
                <DialogContent>
                    <DialogTitle>{pending?.label}</DialogTitle>
                    <DialogDescription>{pending?.confirm}</DialogDescription>
                    <DialogFooter>
                        <Button
                            variant="secondary"
                            onClick={() => setPending(null)}
                        >
                            Hủy
                        </Button>
                        <Button
                            variant={
                                pending?.destructive ? 'destructive' : 'default'
                            }
                            onClick={() => {
                                pending?.run(selected);
                                setPending(null);
                            }}
                        >
                            Đồng ý
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

function SearchBox({
    value,
    placeholder,
    onSearch,
}: {
    value: string;
    placeholder: string;
    onSearch: (value: string) => void;
}) {
    const [text, setText] = useState(value);
    const latest = useRef(onSearch);
    latest.current = onSearch;

    useEffect(() => setText(value), [value]);

    useEffect(() => {
        if (text === value) {
            return;
        }

        const timer = setTimeout(() => latest.current(text.trim()), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [text]);

    return (
        <div className="relative w-full sm:w-80">
            <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                value={text}
                onChange={(event) => setText(event.target.value)}
                placeholder={placeholder}
                className="pl-8"
            />
        </div>
    );
}

function Chip({ label, onRemove }: { label: string; onRemove: () => void }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-1 pl-2.5 text-xs">
            {label}
            <button
                type="button"
                aria-label={`Bỏ lọc ${label}`}
                onClick={onRemove}
                className="rounded-full p-0.5 hover:bg-muted"
            >
                <X className="size-3" />
            </button>
        </span>
    );
}

function SortIcon({ sort, column }: { sort: string; column: string }) {
    if (sort === column) {
        return <ArrowUp className="size-3.5" />;
    }

    if (sort === `-${column}`) {
        return <ArrowDown className="size-3.5" />;
    }

    return <ArrowUpDown className="size-3.5 opacity-40" />;
}

function Footer<T>({
    data,
    onPerPage,
}: {
    data: TableData<T>;
    onPerPage: (perPage: number) => void;
}) {
    const { rows, query } = data;

    if (rows.total === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t px-3 py-2 text-sm text-muted-foreground">
            <div className="flex items-center gap-2">
                <span>
                    {rows.from}–{rows.to} / {formatNumber(rows.total)}
                </span>
                <Select
                    value={String(query.per_page)}
                    onValueChange={(value) => onPerPage(Number(value))}
                >
                    <SelectTrigger size="sm" className="h-7 w-auto gap-1">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {PER_PAGE.map((size) => (
                            <SelectItem key={size} value={String(size)}>
                                {size} / trang
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            {rows.last_page > 1 && (
                <nav className="flex items-center gap-1">
                    {rows.links.map((link, index) => {
                        const label = link.label
                            .replace(/&laquo;\s*\S*/, '‹')
                            .replace(/\S*\s*&raquo;/, '›');

                        return link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                preserveState
                                className={cn(
                                    'min-w-8 rounded-md px-2 py-1 text-center hover:bg-muted',
                                    link.active &&
                                        'bg-primary text-primary-foreground hover:bg-primary',
                                )}
                            >
                                {label}
                            </Link>
                        ) : (
                            <span
                                key={index}
                                className="min-w-8 px-2 py-1 text-center opacity-50"
                            >
                                {label}
                            </span>
                        );
                    })}
                </nav>
            )}
        </div>
    );
}
