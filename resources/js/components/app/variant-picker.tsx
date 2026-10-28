import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type VariantOption = {
    id: number;
    product_id?: number;
    name: string;
    sku: string;
    barcode: string | null;
    unit: string | null;
    image_url?: string | null;
    retail_price: number;
    /** Only when the user may see costs. */
    import_price?: number | null;
    cost_price?: number | null;
    /** Stock in the branch passed as `branchId`. */
    on_hand?: number | null;
    type: string;
};

/**
 * Search box for product variants by name, SKU or barcode (F3 focuses it
 * when `hotkey` is set). Arrow keys + Enter pick a result.
 */
export function VariantPicker({
    onPick,
    types,
    placeholder = 'Tìm theo tên, mã SKU, mã vạch',
    hotkey = false,
    branchId,
    baseOnly = false,
    price = 'retail_price',
}: {
    onPick: (variant: VariantOption) => void;
    types?: string[];
    placeholder?: string;
    hotkey?: boolean;
    /** Also return the stock of this branch. */
    branchId?: number | null;
    /** Leave out conversion units (stock and cost live on the base unit). */
    baseOnly?: boolean;
    /** Price shown in the results. */
    price?: 'retail_price' | 'import_price' | 'cost_price';
}) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<VariantOption[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const typesKey = types?.join(',') ?? '';

    useEffect(() => {
        if (!hotkey) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F3') {
                event.preventDefault();
                input.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [hotkey]);

    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
            const params = new URLSearchParams({ q: term });
            typesKey
                .split(',')
                .filter(Boolean)
                .forEach((type) => params.append('types[]', type));

            if (branchId) {
                params.set('branch_id', String(branchId));
            }

            if (baseOnly) {
                params.set('base_only', '1');
            }

            fetch(`/products/variants/search?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => response.json())
                .then((data: VariantOption[]) => {
                    setResults(data);
                    setActive(0);
                })
                .catch(() => undefined);
        }, 150);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, typesKey, branchId, baseOnly]);

    const pick = (variant: VariantOption | undefined) => {
        if (!variant) {
            return;
        }

        onPick(variant);
        setTerm('');
        setOpen(false);
    };

    return (
        <div className="relative">
            <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
            <Input
                ref={input}
                value={term}
                placeholder={hotkey ? `${placeholder} (F3)` : placeholder}
                className="pl-8"
                onChange={(event) => {
                    setTerm(event.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        setActive((index) =>
                            Math.min(index + 1, results.length - 1),
                        );
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        setActive((index) => Math.max(index - 1, 0));
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        pick(results[active]);
                    } else if (event.key === 'Escape') {
                        setOpen(false);
                    }
                }}
            />
            {open && results.length > 0 && (
                <div className="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-popover p-1 shadow-md">
                    {results.map((variant, index) => (
                        <button
                            type="button"
                            key={variant.id}
                            className={cn(
                                'flex w-full items-center justify-between gap-3 rounded-sm px-2 py-1.5 text-left text-sm',
                                index === active && 'bg-accent',
                            )}
                            onMouseEnter={() => setActive(index)}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => pick(variant)}
                        >
                            <span className="min-w-0">
                                <span className="block truncate">
                                    {variant.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {variant.sku}
                                    {variant.barcode
                                        ? ` · ${variant.barcode}`
                                        : ''}
                                </span>
                            </span>
                            <span className="shrink-0 text-right tabular-nums">
                                {variant[price] != null &&
                                    formatNumber(variant[price] ?? 0)}
                                {variant.on_hand != null && (
                                    <span className="block text-xs text-muted-foreground">
                                        Tồn: {formatNumber(variant.on_hand)}
                                    </span>
                                )}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
