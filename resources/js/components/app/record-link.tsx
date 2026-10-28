import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useCan } from '@/hooks/use-can';

/**
 * Blue record name in a list that opens its detail page, like Sapo. Plain
 * text when the user may not open it.
 */
export function RecordLink({
    href,
    permission,
    children,
}: {
    href: string;
    permission?: string;
    children: ReactNode;
}) {
    const can = useCan();

    if (!can(permission)) {
        return <span>{children}</span>;
    }

    return (
        <Link href={href} className="text-primary hover:underline">
            {children}
        </Link>
    );
}
