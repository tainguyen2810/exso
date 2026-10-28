import { Search } from 'lucide-react';
import {
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
} from 'react';
import { ProductThumb } from '@/components/app/product-thumb';
import { Input } from '@/components/ui/input';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

/** A product row from /orders/variants/search, priced by the order's policy. */
export type SaleVariant = {
    id: number;
    product_id: number;
    name: string;
    sku: string;
    barcode: string | null;
    unit: string | null;
    image_url: string | null;
    type: string;
    price: number;
    retail_price: number;
    exact: boolean;
    on_hand: number | null;
    available: number | null;
};

export type SearchHandle = { focus: () => void };

export async function searchVariants(params: {
    q: string;
    branchId?: number | null;
    pricePolicy?: string | null;
    limit?: number;
    /** Best sellers first when the search box is empty (POS grid). */
    top?: boolean;
    signal?: AbortSignal;
}): Promise<SaleVariant[]> {
    const query = new URLSearchParams({ q: params.q });

    if (params.branchId) {
        query.set('branch_id', String(params.branchId));
    }

    if (params.pricePolicy) {
        query.set('price_policy', params.pricePolicy);
    }

    if (params.top) {
        query.set('top', '1');
    }

    if (params.limit) {
        query.set('limit', String(params.limit));
    }

    const response = await fetch(`/orders/variants/search?${query}`, {
        headers: { Accept: 'application/json' },
        signal: params.signal,
    });

    return response.ok ? response.json() : [];
}

/** New prices of variants under another price policy: {variantId: price}. */
export async function repriceVariants(
    ids: number[],
    pricePolicy: string,
): Promise<Record<string, number>> {
    if (ids.length === 0) {
        return {};
    }

    const query = new URLSearchParams({ price_policy: pricePolicy });
    ids.forEach((id) => query.append('ids[]', String(id)));
    const response = await fetch(`/orders/variants/prices?${query}`, {
        headers: { Accept: 'application/json' },
    });

    return response.ok ? response.json() : {};
}

/**
 * Product search of the order form and POS (F3). Enter on a scanned
 * barcode/SKU adds that product at once, like Sapo.
 */
export const OrderProductSearch = forwardRef<
    SearchHandle,
    {
        onPick: (variant: SaleVariant) => void;
        branchId: number | null;
        pricePolicy: string | null;
        placeholder?: string;
        className?: string;
        autoFocus?: boolean;
    }
>(function OrderProductSearch(
    {
        onPick,
        branchId,
        pricePolicy,
        placeholder = 'Tìm theo tên, mã SKU, hoặc quét mã vạch... (F3)',
        className,
        autoFocus,
    },
    ref,
) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<SaleVariant[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const latest = useRef('');

    useImperativeHandle(ref, () => ({ focus: () => input.current?.focus() }));

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F3') {
                event.preventDefault();
                input.current?.focus();
                input.current?.select();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            latest.current = term;
            searchVariants({
                q: term,
                branchId,
                pricePolicy,
                signal: controller.signal,
            })
                .then((data) => {
                    setResults(data);
                    setActive(0);
                })
                .catch(() => undefined);
        }, 150);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, branchId, pricePolicy, open]);

    const pick = (variant: SaleVariant | undefined) => {
        if (!variant) {
            return;
        }

        onPick(variant);
        setTerm('');
        setOpen(false);
    };

    // A scanner types the code and presses Enter before the debounced search
    // has run: look the code up right away.
    const enter = () => {
        if (term.trim() === '') {
            return;
        }

        if (latest.current !== term) {
            const code = term;
            void searchVariants({
                q: code,
                branchId,
                pricePolicy,
                limit: 1,
            }).then((data) => {
                if (data[0]?.exact) {
                    pick(data[0]);
                } else {
                    setResults(data);
                    setOpen(true);
                }
            });

            return;
        }

        pick(results[active]);
    };

    return (
        <div className={cn('relative', className)}>
            <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
            <Input
                ref={input}
                value={term}
                autoFocus={autoFocus}
                placeholder={placeholder}
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
                        enter();
                    } else if (event.key === 'Escape') {
                        setOpen(false);
                    }
                }}
            />
            {open && results.length > 0 && (
                <div className="absolute z-30 mt-1 max-h-96 w-full overflow-y-auto rounded-md border bg-popover p-1 shadow-md">
                    {results.map((variant, index) => (
                        <button
                            type="button"
                            key={variant.id}
                            className={cn(
                                'flex w-full items-center gap-3 rounded-sm px-2 py-1.5 text-left text-sm',
                                index === active && 'bg-accent',
                            )}
                            onMouseEnter={() => setActive(index)}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => pick(variant)}
                        >
                            <ProductThumb
                                url={variant.image_url}
                                className="size-9"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate">
                                    {variant.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {variant.sku}
                                    {variant.unit ? ` · ${variant.unit}` : ''}
                                </span>
                            </span>
                            <span className="shrink-0 text-right tabular-nums">
                                {formatNumber(variant.price)}
                                {variant.available != null && (
                                    <span className="block text-xs text-muted-foreground">
                                        Có thể bán:{' '}
                                        <span
                                            className={cn(
                                                variant.available <= 0 &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {formatNumber(variant.available)}
                                        </span>
                                    </span>
                                )}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
});
