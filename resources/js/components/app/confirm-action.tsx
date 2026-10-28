import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

/**
 * A button that posts to `url` (or runs `onConfirm`) after a confirmation
 * step, e.g. "Hủy phiếu" or "Cân bằng kho". Errors come back as page errors.
 */
export function ConfirmAction({
    url,
    onConfirm,
    title,
    description,
    confirmLabel,
    trigger,
    destructive = false,
}: {
    url?: string;
    onConfirm?: () => void;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    trigger: ReactNode;
    destructive?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Thoát</Button>
                    </DialogClose>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={() => {
                            if (onConfirm) {
                                setOpen(false);
                                onConfirm();

                                return;
                            }

                            router.post(
                                url ?? '',
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setOpen(false);
                                    },
                                },
                            );
                        }}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
