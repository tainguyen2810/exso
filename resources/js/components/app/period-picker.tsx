import { CalendarDays, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type Period = { from: string; to: string };

const pad = (value: number) => String(value).padStart(2, '0');

/** Date → dd/mm/yyyy in the browser's day (the store works in Asia/Ho_Chi_Minh). */
export const toDmy = (date: Date) =>
    `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;

const shift = (date: Date, days: number) => {
    const next = new Date(date);
    next.setDate(next.getDate() + days);

    return next;
};

/** Quick periods like Sapo's date filter. */
export function periodPresets(today = new Date()): (Period & { label: string })[] {
    const year = today.getFullYear();
    const month = today.getMonth();

    return [
        { label: 'Hôm nay', from: toDmy(today), to: toDmy(today) },
        { label: 'Hôm qua', from: toDmy(shift(today, -1)), to: toDmy(shift(today, -1)) },
        { label: '7 ngày qua', from: toDmy(shift(today, -6)), to: toDmy(today) },
        { label: '30 ngày qua', from: toDmy(shift(today, -29)), to: toDmy(today) },
        { label: 'Tháng này', from: toDmy(new Date(year, month, 1)), to: toDmy(today) },
        {
            label: 'Tháng trước',
            from: toDmy(new Date(year, month - 1, 1)),
            to: toDmy(new Date(year, month, 0)),
        },
        { label: 'Năm nay', from: toDmy(new Date(year, 0, 1)), to: toDmy(today) },
    ];
}

/**
 * "Khoảng thời gian": a button showing the period that opens quick choices
 * and from / to boxes (dd/mm/yyyy).
 */
export function PeriodPicker({
    value,
    onChange,
    label,
    className,
}: {
    value: Period;
    onChange: (period: Period) => void;
    label?: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<Period>(value);
    const presets = periodPresets();
    const current = presets.find(
        (preset) => preset.from === value.from && preset.to === value.to,
    );

    const apply = (period: Period) => {
        setOpen(false);
        onChange(period);
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setDraft(value);
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    className={cn('justify-between gap-2 bg-background font-normal', className)}
                >
                    <CalendarDays className="size-4 text-muted-foreground" />
                    {label && <span className="text-muted-foreground">{label}</span>}
                    <span>
                        {current
                            ? `${current.label} (${value.from} - ${value.to})`
                            : `${value.from} - ${value.to}`}
                    </span>
                    <ChevronDown className="size-4 text-muted-foreground" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-0">
                <div className="grid grid-cols-2 gap-1 border-b p-2">
                    {presets.map((preset) => (
                        <button
                            key={preset.label}
                            type="button"
                            className={cn(
                                'rounded px-2 py-1.5 text-left text-sm hover:bg-accent',
                                current?.label === preset.label && 'text-primary',
                            )}
                            onClick={() => apply(preset)}
                        >
                            {preset.label}
                        </button>
                    ))}
                </div>
                <form
                    className="grid gap-2 p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        apply(draft);
                    }}
                >
                    <div className="grid grid-cols-2 gap-2">
                        <Input
                            value={draft.from}
                            placeholder="Từ ngày"
                            aria-label="Từ ngày"
                            onChange={(event) =>
                                setDraft({ ...draft, from: event.target.value })
                            }
                        />
                        <Input
                            value={draft.to}
                            placeholder="Đến ngày"
                            aria-label="Đến ngày"
                            onChange={(event) =>
                                setDraft({ ...draft, to: event.target.value })
                            }
                        />
                    </div>
                    <Button size="sm">Lọc</Button>
                </form>
            </PopoverContent>
        </Popover>
    );
}
