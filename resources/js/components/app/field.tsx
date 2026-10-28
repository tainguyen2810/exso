import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** A labelled form field with its validation error underneath. */
export function Field({
    label,
    error,
    htmlFor,
    className,
    children,
}: {
    label: ReactNode;
    error?: string;
    htmlFor?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
