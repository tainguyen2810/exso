import { useForm } from '@inertiajs/react';
import { FileSpreadsheet, Download } from 'lucide-react';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

/** "Nhập Excel": download the template, pick a file, see row errors. */
export function ImportDialog({
    url,
    templateUrl,
    title,
    description,
}: {
    url: string;
    templateUrl: string;
    title: string;
    description: string;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ file: File | null }>({ file: null });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-primary"
                >
                    <Download className="size-4" />
                    Nhập file
                </button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                    <a
                        href={templateUrl}
                        className="inline-flex items-center gap-2 text-sm text-primary hover:underline"
                    >
                        <FileSpreadsheet className="size-4" /> Tải tệp mẫu
                        (.xlsx)
                    </a>
                    <div className="grid gap-2">
                        <Input
                            type="file"
                            accept=".xlsx,.csv"
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <InputError message={errors.file} />
                        {errors.rows && (
                            <pre className="max-h-48 overflow-auto rounded-md bg-destructive/5 p-3 font-sans text-xs whitespace-pre-line text-destructive">
                                {errors.rows}
                            </pre>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setOpen(false)}
                        >
                            Hủy
                        </Button>
                        <Button disabled={form.processing || !form.data.file}>
                            Nhập
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
