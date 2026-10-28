import { router } from '@inertiajs/react';
import { Bookmark, Plus, X } from 'lucide-react';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SavedView, TableQuery } from './types';

/** "Bộ lọc đã lưu": each user keeps their own named search/filter/sort per list. */
export function SavedViews({
    tableKey,
    views,
    query,
    onApply,
}: {
    tableKey: string;
    views: SavedView[];
    query: TableQuery;
    onApply: (view: SavedView) => void;
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
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline">
                        <Bookmark />
                        <span className="hidden sm:inline">Bộ lọc đã lưu</span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-64">
                    <DropdownMenuLabel>Bộ lọc đã lưu</DropdownMenuLabel>
                    {views.length === 0 && (
                        <p className="px-2 pb-2 text-xs text-muted-foreground">
                            Chưa có. Lọc danh sách rồi bấm "Lưu bộ lọc hiện
                            tại".
                        </p>
                    )}
                    {views.map((view) => (
                        <DropdownMenuItem
                            key={view.id}
                            onSelect={() => onApply(view)}
                            className="justify-between"
                        >
                            <span className="truncate">{view.name}</span>
                            <button
                                type="button"
                                aria-label={`Xóa bộ lọc ${view.name}`}
                                className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    router.delete(`/saved-views/${view.id}`, {
                                        preserveScroll: true,
                                        preserveState: true,
                                    });
                                }}
                            >
                                <X className="size-3.5" />
                            </button>
                        </DropdownMenuItem>
                    ))}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onSelect={() => setOpen(true)}>
                        <Plus /> Lưu bộ lọc hiện tại
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={save} className="space-y-4">
                        <DialogTitle>Lưu bộ lọc</DialogTitle>
                        <DialogDescription>
                            Lưu từ khóa, điều kiện lọc và cách sắp xếp hiện tại
                            để mở lại nhanh. Chỉ bạn thấy bộ lọc này.
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
