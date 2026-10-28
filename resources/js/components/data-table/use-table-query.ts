import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import type { FilterValue, TableQuery } from './types';

function clean(filter: Record<string, FilterValue | undefined>) {
    const result: Record<string, FilterValue> = {};

    for (const [key, value] of Object.entries(filter)) {
        if (value === undefined || value === '') {
            continue;
        }

        if (typeof value === 'object') {
            const range = Object.fromEntries(
                Object.entries(value).filter(([, item]) => item),
            );

            if (Object.keys(range).length > 0) {
                result[key] = range;
            }

            continue;
        }

        result[key] = value;
    }

    return result;
}

/**
 * Reloads the current list page with a new search/filter/sort. Page resets
 * to 1 unless the change is the page itself.
 */
export function useTableQuery(query: TableQuery, defaultSort?: string) {
    return useCallback(
        (
            patch: Partial<
                Omit<TableQuery, 'filter'> & {
                    filter: Record<string, FilterValue | undefined>;
                    page: number;
                }
            >,
        ) => {
            const next = { ...query, ...patch };
            const filter = clean(next.filter);

            router.get(
                window.location.pathname,
                {
                    search: next.search || undefined,
                    filter: Object.keys(filter).length ? filter : undefined,
                    sort:
                        next.sort && next.sort !== defaultSort
                            ? next.sort
                            : undefined,
                    per_page: next.per_page !== 20 ? next.per_page : undefined,
                    page: patch.page && patch.page > 1 ? patch.page : undefined,
                },
                { preserveState: true, preserveScroll: true },
            );
        },
        [query, defaultSort],
    );
}
