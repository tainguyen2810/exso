import type { ReactNode } from 'react';
import type { Paginated } from '@/types';

export type DateRange = { from?: string; to?: string };

export type FilterValue = string | DateRange;

export type TableQuery = {
    search: string;
    filter: Record<string, FilterValue>;
    sort: string;
    per_page: number;
};

export type SavedView = {
    id: number;
    name: string;
    query: Partial<Pick<TableQuery, 'search' | 'filter' | 'sort'>>;
};

/** What a DataTable controller sends (App\Support\DataTable\DataTable::paginate). */
export type TableData<T> = {
    rows: Paginated<T>;
    query: TableQuery;
    views: SavedView[];
};

export type Column<T> = {
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    /** Column name sent as ?sort=, when the server allows sorting by it. */
    sort?: string;
    /** Hidden until the user turns it on in the column chooser. */
    hidden?: boolean;
    /** Always shown, not listed in the column chooser. */
    fixed?: boolean;
    className?: string;
};

export type FilterOption = { value: string; label: string };

export type FilterDef =
    | { key: string; label: string; type: 'select'; options: FilterOption[] }
    | { key: string; label: string; type: 'date-range' };

export type BulkAction = {
    label: string;
    /** Called with the selected row ids; return nothing, reload is handled by Inertia. */
    run: (ids: number[]) => void;
    destructive?: boolean;
    /** Ask before running. */
    confirm?: string;
};

export type Tabs = {
    filter: string;
    options: FilterOption[];
};
