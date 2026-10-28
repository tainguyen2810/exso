import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

const NONE = '__none__';

export type SelectOption = { value: string; label: string };

/**
 * Select over {value, label} options where "nothing picked" is allowed
 * (Radix Select cannot hold an empty value): `emptyLabel` adds that row.
 */
export function OptionSelect({
    value,
    onChange,
    options,
    placeholder = 'Chọn',
    emptyLabel,
    disabled,
    className,
    invalid,
}: {
    value: string | null | undefined;
    onChange: (value: string | null) => void;
    options: SelectOption[];
    placeholder?: string;
    /** Label of the "none" row; leave out to require a value. */
    emptyLabel?: string;
    disabled?: boolean;
    className?: string;
    invalid?: boolean;
}) {
    return (
        <Select
            value={value ?? (emptyLabel ? NONE : '')}
            onValueChange={(next) => onChange(next === NONE ? null : next)}
            disabled={disabled}
        >
            <SelectTrigger
                className={cn('w-full', className)}
                aria-invalid={invalid}
            >
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {emptyLabel && (
                    <SelectItem value={NONE}>{emptyLabel}</SelectItem>
                )}
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** {id, name} records → select options. */
export const toSelectOptions = (items: { id: number; name: string }[]) =>
    items.map((item) => ({ value: String(item.id), label: item.name }));
