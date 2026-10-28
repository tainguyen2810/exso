import { cn } from '@/lib/utils';

const tones = {
    success: 'border-success-border bg-success-soft text-success',
    warning: 'border-warning-border bg-warning-soft text-warning',
    neutral: 'border-border bg-muted text-muted-foreground',
    info: 'border-primary/30 bg-accent text-primary',
    danger: 'border-destructive/30 bg-destructive/5 text-destructive',
};

export type Tone = keyof typeof tones;

/** Status value → [label, pill tone], e.g. { draft: ['Nháp', 'warning'] }. */
export type StatusMap = Record<string, [string, Tone]>;

/** Filter / tab options of a status map. */
export const statusOptions = (statuses: StatusMap) =>
    Object.entries(statuses).map(([value, [label]]) => ({ value, label }));

/** The pill of one status from a status map. */
export function StatusPill({
    statuses,
    value,
}: {
    statuses: StatusMap;
    value: string | null | undefined;
}) {
    if (!value) {
        return null;
    }

    const [label, tone] = statuses[value] ?? [value, 'neutral'];

    return <Pill tone={tone}>{label}</Pill>;
}

/** Rounded status pill like Sapo's ("Đang hoạt động" in green). */
export function Pill({
    tone = 'neutral',
    className,
    children,
}: {
    tone?: Tone;
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
