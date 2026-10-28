import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { useEffect, useId, useRef } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { PageTitle, TopbarActions } from '@/components/app/topbar';
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
 * Create/edit page layout used by every module, like Sapo: the topbar shows
 * "‹ Quay lại …" with Hủy / Lưu; content is a main column (8/12) and a side
 * column (4/12); Lưu is repeated at the bottom. F1 or Ctrl+S saves.
 */
export function DocumentForm({
    title,
    backHref,
    backLabel = 'Quay lại',
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
    /** Text of the back link in the topbar, e.g. "Quay lại danh sách nhân viên". */
    backLabel?: string;
    onSubmit: () => void;
    processing?: boolean;
    isDirty?: boolean;
    submitLabel?: string;
    aside?: ReactNode;
    /** Extra buttons, e.g. delete; shown at the bottom left like Sapo. */
    actions?: ReactNode;
    children: ReactNode;
}) {
    const id = useId();
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

    const saveButton = (
        <Button
            form={id}
            disabled={processing || !isDirty}
            title="Lưu (F1 hoặc Ctrl+S)"
        >
            {submitLabel}
        </Button>
    );

    return (
        <form id={id} onSubmit={handle} className="flex min-h-full flex-col">
            <PageTitle>
                <Link
                    href={backHref}
                    className="inline-flex items-center gap-1 truncate text-sm text-muted-foreground hover:text-primary"
                >
                    <ChevronLeft className="size-4" />
                    {backLabel}
                </Link>
            </PageTitle>
            <TopbarActions>
                <Button variant="outline" asChild>
                    <Link href={backHref}>Hủy</Link>
                </Button>
                {saveButton}
            </TopbarActions>
            <div className="mx-auto w-full max-w-[1200px] flex-1 space-y-4 px-4 pt-5 pb-10 md:px-8">
                <h2 className="text-xl font-medium">{title}</h2>
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
                <div className="flex items-center gap-2 border-t pt-4">
                    {actions}
                    <span className="ml-auto hidden text-xs text-muted-foreground sm:inline">
                        F1 hoặc Ctrl+S để lưu
                    </span>
                    {saveButton}
                </div>
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
        <Card className="gap-0 py-0">
            <CardHeader className="gap-1 border-b px-5 py-4">
                <CardTitle>{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent className={cn('grid gap-4 px-5 py-5', className)}>
                {children}
            </CardContent>
        </Card>
    );
}
