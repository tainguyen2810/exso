import { useEffect, useState } from 'react';
import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { bpToPercent, percentToBp } from '@/lib/percent';

/** Percent box ("5,5") holding basis points (550); empty means null. */
export function PercentInput({
    value,
    onValueChange,
    ...props
}: Omit<ComponentProps<typeof Input>, 'value' | 'onChange'> & {
    value: number | null | undefined;
    onValueChange: (value: number | null) => void;
}) {
    const [text, setText] = useState(bpToPercent(value));

    useEffect(() => {
        if (percentToBp(text) !== (value ?? null)) {
            setText(bpToPercent(value));
        }
        // Only follow outside changes, not each keystroke.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    return (
        <Input
            inputMode="decimal"
            {...props}
            value={text}
            onChange={(event) => {
                const next = event.target.value.replace(/[^\d.,]/g, '');
                setText(next);
                onValueChange(percentToBp(next));
            }}
        />
    );
}
