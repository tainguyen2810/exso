import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Whole number input shown as 1.234.567 (money in đồng, quantities).
 * Empty is null.
 */
export function MoneyInput({
    value,
    onValueChange,
    allowNegative = false,
    className,
    ...props
}: Omit<ComponentProps<'input'>, 'value' | 'onChange' | 'type'> & {
    value: number | null | undefined;
    onValueChange: (value: number | null) => void;
    allowNegative?: boolean;
}) {
    return (
        <Input
            {...props}
            inputMode="numeric"
            className={cn('text-right tabular-nums', className)}
            value={
                value === null || value === undefined ? '' : formatNumber(value)
            }
            onFocus={(event) => {
                event.currentTarget.select();
                props.onFocus?.(event);
            }}
            onChange={(event) => {
                const raw = event.target.value;
                const negative = allowNegative && raw.trim().startsWith('-');
                const digits = raw.replace(/\D/g, '');

                if (digits === '') {
                    onValueChange(null);

                    return;
                }

                const number = Math.min(Number(digits), 999_999_999_999);
                onValueChange(negative ? -number : number);
            }}
        />
    );
}
