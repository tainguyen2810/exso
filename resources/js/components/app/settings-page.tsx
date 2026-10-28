import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { PageTitle, TopbarActions } from '@/components/app/topbar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCan } from '@/hooks/use-can';
import { cn } from '@/lib/utils';

/**
 * A store settings page like Sapo's: "‹ Cấu hình" in the topbar, then
 * sections with the title and help on the left and a card on the right.
 * With onSave, Hủy / Lưu sit in the topbar and at the bottom, F1 or Ctrl+S
 * saves, and fields are disabled for users without settings.update.
 */
export function SettingsPage({
    title,
    onSave,
    onCancel,
    processing = false,
    isDirty = true,
    permission = 'settings.update',
    children,
}: {
    title: string;
    onSave?: () => void;
    onCancel?: () => void;
    processing?: boolean;
    isDirty?: boolean;
    /** Needed to change anything on the page. */
    permission?: string;
    children: ReactNode;
}) {
    const can = useCan();
    const editable = can(permission);
    const save = useRef<(() => void) | undefined>(undefined);
    save.current = editable && isDirty && !processing ? onSave : undefined;

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                save.current &&
                (event.key === 'F1' ||
                    ((event.ctrlKey || event.metaKey) &&
                        event.key.toLowerCase() === 's'))
            ) {
                event.preventDefault();
                save.current();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const buttons = onSave && editable && (
        <>
            <Button
                variant="outline"
                disabled={!isDirty || processing}
                onClick={onCancel}
            >
                Hủy
            </Button>
            <Button
                disabled={!isDirty || processing}
                onClick={onSave}
                title="Lưu (F1 hoặc Ctrl+S)"
            >
                Lưu
            </Button>
        </>
    );

    return (
        <div className="mx-auto w-full max-w-[1100px] px-4 pt-5 pb-10 md:px-8">
            <PageTitle>
                <Link
                    href="/config"
                    className="inline-flex items-center gap-1 truncate text-sm text-muted-foreground hover:text-primary"
                >
                    <ChevronLeft className="size-4" />
                    Cấu hình
                </Link>
            </PageTitle>
            {buttons && <TopbarActions>{buttons}</TopbarActions>}
            <h2 className="mb-5 text-xl font-medium">{title}</h2>
            {!editable && (
                <div className="mb-5 rounded-md border border-warning-border bg-warning-soft px-4 py-3 text-sm">
                    Bạn chỉ có quyền xem trang này.
                </div>
            )}
            <fieldset disabled={!editable} className="grid min-w-0 gap-8">
                {children}
            </fieldset>
            {buttons && (
                <div className="mt-8 flex justify-end gap-2 border-t pt-4">
                    {buttons}
                </div>
            )}
        </div>
    );
}

/** One section: title and help on the left (1/3), card on the right (2/3). */
export function SettingsSection({
    title,
    description,
    className,
    children,
}: {
    title: string;
    description?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] md:gap-8">
            <div>
                <h3 className="font-medium">{title}</h3>
                {description && (
                    <div className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </div>
                )}
            </div>
            <Card className={cn('gap-0 px-5 py-5', className)}>{children}</Card>
        </section>
    );
}

/** Add/edit popup for one row of a settings list (thuế, nguồn, lý do...). */
export function RecordDialog({
    open,
    onOpenChange,
    title,
    description,
    onSubmit,
    processing,
    extra,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    onSubmit: () => void;
    processing: boolean;
    /** Shown at the bottom left, e.g. a delete button. */
    extra?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit();
                    }}
                >
                    <div>
                        <DialogTitle>{title}</DialogTitle>
                        {description && (
                            <DialogDescription className="mt-1">
                                {description}
                            </DialogDescription>
                        )}
                    </div>
                    {children}
                    <DialogFooter className="gap-2 sm:justify-between">
                        <div>{extra}</div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                            >
                                Hủy
                            </Button>
                            <Button disabled={processing}>Lưu</Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
