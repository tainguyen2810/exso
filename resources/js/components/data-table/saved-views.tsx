import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { SavedView, TableQuery } from './types';

/** Whether a saved view is what the list currently shows. */
export function isCurrentView(view: SavedView, query: TableQuery): boolean {
    const same = (a: unknown, b: unknown) =>
        JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
    const filter = (value: Record<string, unknown> | undefined) =>
        value && Object.keys(value).length > 0
            ? Object.fromEntries(
                  Object.entries(value).sort(([a], [b]) => a.localeCompare(b)),
              )
            : null;

    return (
        (view.query.search ?? '') === query.search &&
        same(filter(view.query.filter), filter(query.filter)) &&
        (!view.query.sort || view.query.sort === query.sort)
    );
}

/**
 * A saved filter shown as a tab, like Sapo. Each user keeps their own
 * named search/filter/sort per list.
 */
export function SavedViewTab({
    view,
    active,
    onApply,
}: {
    view: SavedView;
    active: boolean;
    onApply: () => void;
}) {
    return (
        <span
            className={cn(
                'group -mb-px inline-flex h-12 items-center gap-1 border-b-2 border-transparent text-sm whitespace-nowrap text-muted-foreground hover:text-foreground',
                active && 'border-primary font-medium text-primary',
            )}
        >
            <button type="button" onClick={onApply}>
                {view.name}
            </button>
            <button
                type="button"
                aria-label={`Xóa bộ lọc ${view.name}`}
                className="rounded p-0.5 opacity-0 group-hover:opacity-100 hover:bg-muted hover:text-destructive focus:opacity-100"
                onClick={() =>
                    router.delete(`/saved-views/${view.id}`, {
                        preserveScroll: true,
                        preserveState: true,
                    })
                }
            >
                <X className="size-3.5" />
            </button>
        </span>
    );
}

/** "Lưu bộ lọc": saves the current search/filter/sort as a new tab. */
export function SaveViewButton({
    tableKey,
    query,
    disabled,
}: {
    tableKey: string;
    query: TableQuery;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [error, setError] = useState<string>();
    const [processing, setProcessing] = useState(false);

    const save = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            '/saved-views',
            {
                table_key: tableKey,
                name,
                query: {
                    search: query.search,
                    filter: query.filter,
                    sort: query.sort,
                },
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => setError(errors.name),
                onSuccess: () => {
                    setOpen(false);
                    setName('');
                    setError(undefined);
                },
            },
        );
    };

    return (
        <>
            <Button
                variant="outline"
                disabled={disabled}
                onClick={() => setOpen(true)}
                title="Lưu điều kiện lọc hiện tại thành một tab"
            >
                Lưu bộ lọc
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={save} className="space-y-4">
                        <DialogTitle>Lưu bộ lọc</DialogTitle>
                        <DialogDescription>
                            Từ khóa, điều kiện lọc và cách sắp xếp hiện tại sẽ
                            thành một tab để mở lại nhanh. Chỉ bạn thấy tab này.
                        </DialogDescription>
                        <div className="grid gap-2">
                            <Label htmlFor="view-name">Tên bộ lọc</Label>
                            <Input
                                id="view-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                placeholder="VD: Nhân viên đang nghỉ"
                                maxLength={100}
                                autoFocus
                            />
                            <InputError message={error} />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setOpen(false)}
                            >
                                Hủy
                            </Button>
                            <Button disabled={processing || !name.trim()}>
                                Lưu
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
