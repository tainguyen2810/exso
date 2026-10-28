import { cn } from '@/lib/utils';

const tones = {
    success: 'border-success-border bg-success-soft text-success',
    warning: 'border-warning-border bg-warning-soft text-warning',
    neutral: 'border-border bg-muted text-muted-foreground',
    info: 'border-primary/30 bg-accent text-primary',
    danger: 'border-destructive/30 bg-destructive/5 text-destructive',
};

/** Rounded status pill like Sapo's ("Đang hoạt động" in green). */
export function Pill({
    tone = 'neutral',
    className,
    children,
}: {
    tone?: keyof typeof tones;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex h-6 items-center rounded-full border px-2.5 text-xs whitespace-nowrap',
                tones[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}

export function StatusBadge({ status }: { status: string }) {
    return status === 'active' ? (
        <Pill tone="success">Đang hoạt động</Pill>
    ) : (
        <Pill>Ngừng hoạt động</Pill>
    );
}
