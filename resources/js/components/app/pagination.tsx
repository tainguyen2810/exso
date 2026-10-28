import { Link } from '@inertiajs/react';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.total === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t px-3 py-2 text-sm text-muted-foreground">
            <span>
                Hiển thị {page.from}–{page.to} trên tổng{' '}
                {formatNumber(page.total)}
            </span>
            {page.last_page > 1 && (
                <nav className="flex items-center gap-1">
                    {page.links.map((link, index) => {
                        const label = link.label
                            .replace('&laquo; Previous', '‹')
                            .replace('Next &raquo;', '›')
                            .replace('&laquo;', '‹')
                            .replace('&raquo;', '›');

                        return link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
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
