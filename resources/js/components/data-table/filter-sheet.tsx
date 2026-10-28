import { SlidersHorizontal } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { DateRange, FilterDef, FilterValue } from './types';

const ALL = '__all';

/** Filter drawer (F10). Changes apply only when the user presses "Lọc". */
export function FilterSheet({
    filters,
    value,
    onApply,
    open,
    onOpenChange,
}: {
    filters: FilterDef[];
    value: Record<string, FilterValue>;
    onApply: (value: Record<string, FilterValue | undefined>) => void;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [draft, setDraft] = useState(value);

    useEffect(() => {
        if (open) {
            setDraft(value);
        }
    }, [open, value]);

    const set = (key: string, next: FilterValue | undefined) =>
        setDraft((current) => {
            const copy = { ...current };

            if (next === undefined) {
                delete copy[key];
            } else {
                copy[key] = next;
            }

            return copy;
        });

    const apply = (next: Record<string, FilterValue | undefined>) => {
        const all: Record<string, FilterValue | undefined> = {};
        filters.forEach((filter) => {
            const value = next[filter.key];

            // Only whole dates (dd/mm/yyyy) are sent; half-typed ones are dropped.
            all[filter.key] =
                filter.type === 'date-range' &&
                value &&
                typeof value === 'object'
                    ? {
                          from:
                              value.from?.length === 10
                                  ? value.from
                                  : undefined,
                          to: value.to?.length === 10 ? value.to : undefined,
                      }
                    : value;
        });
        onApply(all);
        onOpenChange(false);
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex flex-col gap-0">
                <SheetHeader className="border-b">
                    <SheetTitle>Bộ lọc</SheetTitle>
                    <SheetDescription>
                        Chọn điều kiện rồi bấm Lọc.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex-1 space-y-5 overflow-y-auto p-4">
                    {filters.map((filter) => (
                        <div key={filter.key} className="grid gap-2">
                            <Label>{filter.label}</Label>
                            {filter.type === 'select' ? (
                                <Select
                                    value={(draft[filter.key] as string) ?? ALL}
                                    onValueChange={(next) =>
                                        set(
                                            filter.key,
                                            next === ALL ? undefined : next,
                                        )
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            Tất cả
                                        </SelectItem>
                                        {filter.options.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <div className="grid grid-cols-2 gap-2">
                                    {(['from', 'to'] as const).map((edge) => {
                                        const range = (draft[filter.key] ??
                                            {}) as DateRange;

                                        return (
                                            <Input
                                                key={edge}
                                                inputMode="numeric"
                                                placeholder={
                                                    edge === 'from'
                                                        ? 'Từ dd/mm/yyyy'
                                                        : 'Đến dd/mm/yyyy'
                                                }
                                                aria-label={`${filter.label} ${edge === 'from' ? 'từ' : 'đến'}`}
                                                value={range[edge] ?? ''}
                                                onChange={(event) =>
                                                    set(filter.key, {
                                                        ...range,
                                                        [edge]:
                                                            maskDate(
                                                                event.target
                                                                    .value,
                                                            ) || undefined,
                                                    })
                                                }
                                            />
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
                <SheetFooter className="flex-row justify-end border-t">
                    <Button variant="outline" onClick={() => apply({})}>
                        Xóa bộ lọc
                    </Button>
                    <Button onClick={() => apply(draft)}>Lọc</Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

export function FilterButton({
    count,
    onClick,
}: {
    count: number;
    onClick: () => void;
}) {
    return (
        <Button variant="outline" onClick={onClick} title="Bộ lọc (F10)">
            <SlidersHorizontal />
            Bộ lọc
            {count > 0 && (
                <Badge className="h-5 min-w-5 rounded-full px-1.5">
                    {count}
                </Badge>
            )}
        </Button>
    );
}

/** Text shown in the chip of an active filter, e.g. "Trạng thái: Đang hoạt động". */
export function describeFilter(filter: FilterDef, value: FilterValue): string {
    if (filter.type === 'select') {
        const label =
            filter.options.find((option) => option.value === value)?.label ??
            (typeof value === 'string' ? value : '');

        return `${filter.label}: ${label}`;
    }

    const range = value as DateRange;

    return `${filter.label}: ${range.from ?? '…'} – ${range.to ?? '…'}`;
}

/** Typing "28102026" gives "28/10/2026". */
function maskDate(text: string): string {
    const digits = text.replace(/\D/g, '').slice(0, 8);

    return [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4)]
        .filter(Boolean)
        .join('/');
}
