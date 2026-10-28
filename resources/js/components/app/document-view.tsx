import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageErrors } from '@/components/app/page-errors';
import { PageTitle, TopbarActions } from '@/components/app/topbar';

/**
 * Detail page of a document (purchase receipt, order...) like Sapo: back
 * link and action buttons in the topbar, code with status pills, then a
 * main column (8/12) and a side column (4/12).
 */
export function DocumentView({
    title,
    badges,
    meta,
    backHref,
    backLabel,
    actions,
    aside,
    children,
}: {
    title: string;
    badges?: ReactNode;
    /** Small text under the title, e.g. who created it and when. */
    meta?: ReactNode;
    backHref: string;
    backLabel: string;
    actions?: ReactNode;
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <>
            <PageTitle>
                <Link
                    href={backHref}
                    className="inline-flex items-center gap-1 truncate text-sm text-muted-foreground hover:text-primary"
                >
                    <ChevronLeft className="size-4" />
                    {backLabel}
                </Link>
            </PageTitle>
            {actions && <TopbarActions>{actions}</TopbarActions>}
            <div className="mx-auto w-full max-w-[1200px] space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-xl font-medium">{title}</h2>
                    {badges}
                    {meta && (
                        <span className="w-full text-sm text-muted-foreground">
                            {meta}
                        </span>
                    )}
                </div>
                <PageErrors />
                <div className="grid min-w-0 items-start gap-4 lg:grid-cols-12">
                    <div className="min-w-0 space-y-4 lg:col-span-8">
                        {children}
                    </div>
                    {aside && (
                        <div className="space-y-4 lg:col-span-4">{aside}</div>
                    )}
                </div>
            </div>
        </>
    );
}
