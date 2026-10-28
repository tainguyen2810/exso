import type { ReactNode } from 'react';
import { PageTitle } from '@/components/app/topbar';

/**
 * Title goes to the topbar; below it a Sapo-style action row with text
 * links on the left (e.g. Xuất file / Nhập file) and buttons on the right.
 * List pages can instead pass the buttons to DataTable `actions`.
 */
export function PageHeader({
    title,
    actions,
    links,
}: {
    title: string;
    /** Kept for older pages; Sapo shows no description, so it isn't rendered. */
    description?: string;
    actions?: ReactNode;
    links?: ReactNode;
}) {
    return (
        <>
            <PageTitle>
                <h1 className="truncate text-xl font-medium">{title}</h1>
            </PageTitle>
            {(actions || links) && (
                <div className="flex min-h-9 flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-4">{links}</div>
                    <div className="flex items-center gap-2">{actions}</div>
                </div>
            )}
        </>
    );
}

/** A text link with icon in the action row, like Sapo's "Xuất file". */
export function PageLink({
    icon: Icon,
    children,
    ...props
}: React.ComponentProps<'a'> & {
    icon?: React.ComponentType<{ className?: string }>;
}) {
    return (
        <a
            {...props}
            className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-primary"
        >
            {Icon && <Icon className="size-4" />}
            {children}
        </a>
    );
}
