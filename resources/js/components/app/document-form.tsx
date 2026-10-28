import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Create/edit page layout used by every module: title bar with back link,
 * main column (8/12) and side column (4/12), sticky save bar. F1 or Ctrl+S
 * saves.
 */
export function DocumentForm({
    title,
    backHref,
    onSubmit,
    processing = false,
    isDirty = true,
    submitLabel = 'Lưu',
    aside,
    actions,
    children,
}: {
    title: string;
    backHref: string;
    onSubmit: () => void;
    processing?: boolean;
    isDirty?: boolean;
    submitLabel?: string;
    aside?: ReactNode;
    /** Extra buttons next to Save (e.g. delete). */
    actions?: ReactNode;
    children: ReactNode;
}) {
    const submit = useRef(onSubmit);
    submit.current = onSubmit;

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                event.key === 'F1' ||
                ((event.ctrlKey || event.metaKey) &&
                    event.key.toLowerCase() === 's')
            ) {
                event.preventDefault();
                submit.current();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const handle = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };

    return (
        <form onSubmit={handle} className="flex min-h-full flex-col">
            <div className="flex-1 space-y-4 p-4 md:p-6">
                <div className="flex items-center gap-2">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={backHref} aria-label="Quay lại">
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <h1 className="text-xl font-semibold tracking-tight">
                        {title}
                    </h1>
                </div>
                <div className="grid items-start gap-4 lg:grid-cols-12">
                    <div
                        className={cn(
                            'space-y-4',
                            aside ? 'lg:col-span-8' : 'lg:col-span-12',
                        )}
                    >
                        {children}
                    </div>
                    {aside && (
                        <div className="space-y-4 lg:col-span-4">{aside}</div>
                    )}
                </div>
            </div>
            <div className="sticky bottom-0 z-10 flex items-center justify-end gap-2 border-t bg-background/95 px-4 py-3 backdrop-blur md:px-6">
                <span className="mr-auto hidden text-xs text-muted-foreground sm:inline">
                    Nhấn F1 hoặc Ctrl+S để lưu
                </span>
                {actions}
                <Button variant="outline" asChild>
                    <Link href={backHref}>Hủy</Link>
                </Button>
                <Button disabled={processing || !isDirty}>{submitLabel}</Button>
            </div>
        </form>
    );
}

/** A titled card inside DocumentForm. */
export function FormSection({
    title,
    description,
    className,
    children,
}: {
    title: string;
    description?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent className={cn('grid gap-4', className)}>
                {children}
            </CardContent>
        </Card>
    );
}
