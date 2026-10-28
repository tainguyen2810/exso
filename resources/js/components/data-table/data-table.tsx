import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronDown,
    ChevronRight,
    Search,
    Upload,
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { isCurrentView, SavedViewTab, SaveViewButton } from './saved-views';
import type {
    BulkAction,
    Column,
    FilterDef,
    FilterValue,
    TableData,
    Tabs,
} from './types';
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
    /** Extra links next to "Xuất file" above the list, e.g. import. */
    toolbar?: ReactNode;
    /** Main buttons on the right above the list, e.g. "Thêm nhân viên". */
    actions?: ReactNode;
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
    actions,
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
    // First column holds the column chooser and the select checkbox.
    const colSpan = 1 + shown.length + (expand ? 1 : 0) + (rowActions ? 1 : 0);

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

    const currentView = views.find((view) => isCurrentView(view, query));
    const builtInTabs = tabs
        ? [{ value: '', label: 'Tất cả' }, ...tabs.options]
        : [{ value: '', label: 'Tất cả' }];
    const tabFilterOnly = Object.keys(query.filter).every(
        (key) => key === tabs?.filter,
    );
    const isBuiltInTab = (value: string) =>
        !currentView &&
        !query.search &&
        tabFilterOnly &&
        (tabs ? (query.filter[tabs.filter] ?? '') === value : value === '');
    const inlineFilters = filters
        .filter(
            (filter): filter is Extract<FilterDef, { type: 'select' }> =>
                filter.type === 'select' && filter.key !== tabs?.filter,
        )
        .slice(0, 3);
    const hasConditions =
        query.search !== '' || Object.keys(query.filter).length > 0;

    return (
        <div className="space-y-4">
            {(exportable || toolbar || actions) && (
                <div className="flex min-h-9 flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-4">
                        {exportable && (
                            <a
                                href={exportUrl()}
                                title="Xuất danh sách đang lọc ra Excel"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-primary"
                            >
                                <Upload className="size-4" />
                                Xuất file
                            </a>
                        )}
                        {toolbar}
                    </div>
                    {actions && (
                        <div className="flex items-center gap-2">{actions}</div>
                    )}
                </div>
            )}

            <Card className="gap-0 overflow-hidden py-0">
                <div className="flex gap-6 overflow-x-auto border-b px-4">
                    {builtInTabs.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() =>
                                navigate({
                                    search: '',
                                    filter: tabs
                                        ? {
                                              [tabs.filter]:
                                                  tab.value || undefined,
                                          }
                                        : {},
                                })
                            }
                            className={cn(
                                '-mb-px h-12 border-b-2 border-transparent text-sm whitespace-nowrap text-muted-foreground hover:text-foreground',
                                isBuiltInTab(tab.value) &&
                                    'border-primary font-medium text-primary hover:text-primary',
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                    {views.map((view) => (
                        <SavedViewTab
                            key={view.id}
                            view={view}
                            active={currentView?.id === view.id}
                            onApply={() =>
                                navigate({
                                    search: view.query.search ?? '',
                                    filter: view.query.filter ?? {},
                                    sort: view.query.sort ?? '',
                                })
                            }
                        />
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-2 p-4">
                    <SearchBox
                        value={query.search}
                        placeholder={searchPlaceholder}
                        onSearch={(search) => navigate({ search })}
                    />
                    {inlineFilters.map((filter) => (
                        <InlineFilter
                            key={filter.key}
                            filter={filter}
                            value={query.filter[filter.key]}
                            onChange={(value) =>
                                navigate({
                                    filter: {
                                        ...query.filter,
                                        [filter.key]: value,
                                    },
                                })
                            }
                        />
                    ))}
                    {filters.length > 0 && (
                        <FilterButton
                            label={
                                inlineFilters.length > 0
                                    ? 'Bộ lọc khác'
                                    : 'Bộ lọc'
                            }
                            count={activeFilters.length}
                            onClick={() => setFilterOpen(true)}
                        />
                    )}
                    <SaveViewButton
                        tableKey={tableKey}
                        query={query}
                        disabled={!hasConditions || currentView !== undefined}
                    />
                </div>

                {(activeFilters.length > 0 || query.search) && (
                    <div className="flex flex-wrap items-center gap-2 px-4 pb-3">
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

                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="w-12 pr-0">
                                <div className="flex items-center gap-1">
                                    <ColumnChooser
                                        columns={columns}
                                        visible={visible}
                                        onToggle={toggle}
                                        onReset={reset}
                                    />
                                    {selectable && (
                                        <Checkbox
                                            aria-label="Chọn tất cả trên trang"
                                            checked={allSelected}
                                            disabled={pageIds.length === 0}
                                            onCheckedChange={() =>
                                                setSelected(
                                                    allSelected ? [] : pageIds,
                                                )
                                            }
                                        />
                                    )}
                                </div>
                            </TableHead>
                            {selected.length > 0 ? (
                                <TableHead colSpan={colSpan - 1}>
                                    <BulkBar
                                        count={selected.length}
                                        actions={bulkActions}
                                        onRun={run}
                                        onClear={() => setSelected([])}
                                    />
                                </TableHead>
                            ) : (
                                <>
                                    {expand && (
                                        <TableHead className="w-8 px-1" />
                                    )}
                                    {shown.map((column) => (
                                        <TableHead
                                            key={column.key}
                                            className={column.className}
                                        >
                                            {column.sort ? (
                                                <button
                                                    type="button"
                                                    className="inline-flex items-center gap-1 hover:text-primary"
                                                    onClick={() =>
                                                        sortBy(column.sort!)
                                                    }
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
                                    {rowActions && (
                                        <TableHead className="w-24" />
                                    )}
                                </>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.data.map((row) => {
                            const id = rowId(row);
                            const isOpen = expanded.includes(id);
                            const toggleRow = () =>
                                setSelected((current) =>
                                    current.includes(id)
                                        ? current.filter((item) => item !== id)
                                        : [...current, id],
                                );

                            return (
                                <Fragment key={id}>
                                    <TableRow
                                        data-state={
                                            selected.includes(id)
                                                ? 'selected'
                                                : undefined
                                        }
                                    >
                                        <TableCell className="pr-0">
                                            {selectable && canSelect(row) && (
                                                <Checkbox
                                                    className="ml-8"
                                                    aria-label="Chọn dòng"
                                                    checked={selected.includes(
                                                        id,
                                                    )}
                                                    onCheckedChange={toggleRow}
                                                />
                                            )}
                                        </TableCell>
                                        {expand && (
                                            <TableCell className="px-1">
                                                <button
                                                    type="button"
                                                    aria-label="Xem chi tiết"
                                                    className="rounded p-1 text-muted-foreground hover:bg-muted"
                                                    onClick={() =>
                                                        setExpanded(
                                                            (current) =>
                                                                isOpen
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
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableCell
                                                colSpan={colSpan}
                                                className="h-auto py-3"
                                            >
                                                {expand(row)}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </Fragment>
                            );
                        })}
                        {rows.data.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell
                                    colSpan={colSpan}
                                    className="h-auto py-16 text-center text-muted-foreground"
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
                        <DialogDescription>
                            {pending?.confirm}
                        </DialogDescription>
                        <DialogFooter>
                            <Button
                                variant="secondary"
                                onClick={() => setPending(null)}
                            >
                                Hủy
                            </Button>
                            <Button
                                variant={
                                    pending?.destructive
                                        ? 'destructive'
                                        : 'default'
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
        </div>
    );
}

/** Sapo shows the selection and its actions in place of the column headers. */
function BulkBar({
    count,
    actions,
    onRun,
    onClear,
}: {
    count: number;
    actions: BulkAction[];
    onRun: (action: BulkAction) => void;
    onClear: () => void;
}) {
    return (
        <div className="flex items-center gap-3">
            <span>
                Đã chọn <strong>{formatNumber(count)}</strong> dòng trên trang
                này
            </span>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button size="sm" variant="outline" className="h-8">
                        Chọn thao tác <ChevronDown />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    {actions.map((action) => (
                        <DropdownMenuItem
                            key={action.label}
                            variant={
                                action.destructive ? 'destructive' : 'default'
                            }
                            onSelect={() => onRun(action)}
                        >
                            {action.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
            <button
                type="button"
                className="font-normal text-primary hover:underline"
                onClick={onClear}
            >
                Bỏ chọn
            </button>
        </div>
    );
}

const ALL = '__all';

/** A select filter shown directly in the toolbar, like Sapo's dropdowns. */
function InlineFilter({
    filter,
    value,
    onChange,
}: {
    filter: Extract<FilterDef, { type: 'select' }>;
    value: FilterValue | undefined;
    onChange: (value: string | undefined) => void;
}) {
    return (
        <Select
            value={typeof value === 'string' ? value : ALL}
            onValueChange={(next) => onChange(next === ALL ? undefined : next)}
        >
            <SelectTrigger
                className="hidden w-auto min-w-36 gap-2 md:flex"
                aria-label={filter.label}
            >
                {typeof value === 'string' ? (
                    <SelectValue />
                ) : (
                    <span className="text-foreground">{filter.label}</span>
                )}
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>
                    Tất cả {filter.label.toLowerCase()}
                </SelectItem>
                {filter.options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
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
        <div className="relative min-w-60 flex-1">
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
        <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 border-t px-4 py-3 text-sm text-muted-foreground">
            <div className="flex items-center gap-2">
                <span>Hiển thị</span>
                <Select
                    value={String(query.per_page)}
                    onValueChange={(value) => onPerPage(Number(value))}
                >
                    <SelectTrigger size="sm" className="h-8 w-auto gap-1">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {PER_PAGE.map((size) => (
                            <SelectItem key={size} value={String(size)}>
                                {size}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <span>kết quả</span>
            </div>
            <span>
                Từ {formatNumber(rows.from ?? 0)} đến{' '}
                {formatNumber(rows.to ?? 0)} trên tổng{' '}
                {formatNumber(rows.total)}
            </span>
            {rows.last_page > 1 && (
                <nav
                    className="flex items-center gap-1"
                    aria-label="Phân trang"
                >
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
                                    'min-w-8 rounded-[3px] px-2 py-1 text-center text-foreground hover:bg-muted',
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
