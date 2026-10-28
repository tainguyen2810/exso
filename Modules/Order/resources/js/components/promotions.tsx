import { Gift, Loader2, TicketPercent } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatNumber } from '@/lib/format';
import { sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';

/** A program the order qualifies for (best value first). */
export type AvailablePromotion = {
    id: number;
    code: string;
    name: string;
    kind: 'discount' | 'gift';
    method_label: string;
    description: string | null;
    ends_at: string | null;
    discount: number;
    gift_value: number;
    value: number;
    gifts: {
        key: string;
        quantity: number;
        variant_id: number;
        options: { id: number; name: string; sku: string }[];
    }[];
};

export type PromotionPreview = {
    available: AvailablePromotion[];
    applied: {
        promotion_id: number | null;
        coupon_id: number | null;
        name: string;
        code: string | null;
        amount: number;
    }[];
    errors: Record<string, string>;
    lines: {
        price: number;
        promotion_id: number | null;
        promotion_discount: number;
    }[];
    gifts: {
        variant_id: number;
        promotion_id: number | null;
        name: string;
        sku: string | null;
        unit: string | null;
        quantity: number;
    }[];
    promotion_discount: number;
};

/** What the server needs to price the order (same body as saving). */
export type PromotionRequest = {
    pos?: boolean;
    order_id?: number | null;
    branch_id?: number | null;
    customer_id: number | null;
    source_id?: number | null;
    price_policy: string;
    items: {
        variant_id: number | null;
        name?: string;
        quantity: number;
        price: number;
        discount: number;
    }[];
};

export type PromotionChoice = {
    promotion_ids: number[];
    coupon_code: string;
    gifts: Record<string, number>;
};

export const noPromotions: PromotionChoice = {
    promotion_ids: [],
    coupon_code: '',
    gifts: {},
};

export const hasChoice = (choice: PromotionChoice) =>
    choice.promotion_ids.length > 0 || choice.coupon_code.trim() !== '';

const fetchPreview = (request: PromotionRequest, choice: PromotionChoice) =>
    sendJson<PromotionPreview>('POST', '/orders/promotions', {
        ...request,
        ...choice,
    });

/**
 * The server's pricing of the order with the chosen promotions, fetched
 * again (debounced) whenever the cart or the choice changes. The result
 * is only used while it matches the cart it was computed for.
 */
export function usePromotionPreview(
    request: PromotionRequest,
    choice: PromotionChoice,
) {
    const signature = JSON.stringify([request, choice]);
    const [state, setState] = useState<{
        signature: string;
        preview: PromotionPreview;
    } | null>(null);
    const [loading, setLoading] = useState(false);
    const active = hasChoice(choice) && request.items.length > 0;

    useEffect(() => {
        if (!active) {
            return;
        }

        let cancelled = false;
        const timer = setTimeout(() => {
            setLoading(true);
            fetchPreview(request, choice)
                .then((preview) => {
                    if (!cancelled) {
                        setState({ signature, preview });
                    }
                })
                .catch(() => undefined)
                .finally(() => !cancelled && setLoading(false));
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [signature, active]);

    const current =
        active && state?.signature === signature ? state.preview : null;

    return {
        preview: current,
        loading: active && (loading || current === null),
        /** Promotion discount of the line at `index` of the request. */
        lineDiscount: (index: number) =>
            current?.lines[index]?.promotion_discount ?? 0,
        orderDiscount: current?.promotion_discount ?? 0,
        gifts: current?.gifts ?? [],
        applied: current?.applied ?? [],
        errors: current?.errors ?? {},
    };
}

/**
 * "Áp dụng chương trình khuyến mại": lists the programs the order
 * qualifies for (computed by the server, best value first), lets the user
 * pick one (or several when the store allows) and the gift to give.
 */
export function PromotionDialog({
    open,
    onOpenChange,
    request,
    choice,
    multiple,
    onApply,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    request: PromotionRequest;
    choice: PromotionChoice;
    multiple: boolean;
    onApply: (choice: PromotionChoice) => void;
}) {
    const [offers, setOffers] = useState<AvailablePromotion[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [picked, setPicked] = useState<number[]>([]);
    const [gifts, setGifts] = useState<Record<string, number>>({});
    const requestRef = useRef(request);
    requestRef.current = request;

    useEffect(() => {
        if (!open) {
            return;
        }

        setOffers(null);
        setError(null);
        setPicked(choice.promotion_ids);
        setGifts(choice.gifts);
        // Ask without a choice to see every program on offer.
        fetchPreview(requestRef.current, { ...noPromotions })
            .then((preview) => {
                setOffers(preview.available);

                // Nothing chosen yet: the best program is preselected.
                if (
                    choice.promotion_ids.length === 0 &&
                    preview.available.length > 0
                ) {
                    setPicked([preview.available[0].id]);
                }
            })
            .catch((exception: Error) => setError(exception.message));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const toggle = (id: number) =>
        setPicked((current) =>
            multiple
                ? current.includes(id)
                    ? current.filter((item) => item !== id)
                    : [...current, id]
                : current.includes(id)
                  ? []
                  : [id],
        );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogTitle>Áp dụng chương trình khuyến mại</DialogTitle>
                <div className="-mx-6 max-h-[60vh] overflow-y-auto px-6">
                    {request.items.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            Thêm sản phẩm vào đơn trước khi áp dụng khuyến mại.
                        </p>
                    ) : error ? (
                        <p className="py-8 text-center text-sm text-destructive">
                            {error}
                        </p>
                    ) : offers === null ? (
                        <p className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" /> Đang tìm
                            chương trình phù hợp…
                        </p>
                    ) : offers.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            Không có chương trình khuyến mại nào áp dụng được
                            cho đơn hàng này.
                        </p>
                    ) : (
                        <ul className="grid gap-2">
                            {offers.map((offer, index) => {
                                const checked = picked.includes(offer.id);

                                return (
                                    <li
                                        key={offer.id}
                                        className={cn(
                                            'rounded-md border p-3',
                                            checked &&
                                                'border-primary bg-primary/5',
                                        )}
                                    >
                                        <label className="flex cursor-pointer items-start gap-3">
                                            <input
                                                type={
                                                    multiple
                                                        ? 'checkbox'
                                                        : 'radio'
                                                }
                                                name="promotion"
                                                className="mt-1 accent-primary"
                                                checked={checked}
                                                onChange={() =>
                                                    toggle(offer.id)
                                                }
                                                onClick={() => {
                                                    // A radio can be unticked too.
                                                    if (!multiple && checked) {
                                                        setPicked([]);
                                                    }
                                                }}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-2 font-medium">
                                                    {offer.kind === 'gift' ? (
                                                        <Gift className="size-4 text-primary" />
                                                    ) : (
                                                        <TicketPercent className="size-4 text-primary" />
                                                    )}
                                                    {offer.name}
                                                    {index === 0 && (
                                                        <span className="rounded-full bg-success/10 px-2 text-xs font-normal text-success">
                                                            Tốt nhất
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {offer.code} ·{' '}
                                                    {offer.method_label}
                                                    {offer.ends_at
                                                        ? ` · đến ${formatDate(offer.ends_at)}`
                                                        : ''}
                                                </span>
                                                {offer.description && (
                                                    <span className="mt-1 block text-xs">
                                                        {offer.description}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="text-right text-sm whitespace-nowrap">
                                                {offer.discount > 0 && (
                                                    <span className="block font-medium text-primary tabular-nums">
                                                        −
                                                        {formatNumber(
                                                            offer.discount,
                                                        )}
                                                    </span>
                                                )}
                                                {offer.gift_value > 0 && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        Quà trị giá{' '}
                                                        {formatNumber(
                                                            offer.gift_value,
                                                        )}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                        {checked &&
                                            offer.gifts.map((gift) => (
                                                <div
                                                    key={gift.key}
                                                    className="mt-2 ml-7 flex flex-wrap items-center gap-2 text-sm"
                                                >
                                                    <span className="text-muted-foreground">
                                                        Quà tặng (
                                                        {formatNumber(
                                                            gift.quantity,
                                                        )}
                                                        ):
                                                    </span>
                                                    {gift.options.length > 1 ? (
                                                        <select
                                                            className="h-8 rounded-md border bg-background px-2 text-sm"
                                                            value={
                                                                gifts[
                                                                    gift.key
                                                                ] ??
                                                                gift.variant_id
                                                            }
                                                            onChange={(event) =>
                                                                setGifts({
                                                                    ...gifts,
                                                                    [gift.key]:
                                                                        Number(
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        ),
                                                                })
                                                            }
                                                        >
                                                            {gift.options.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.id
                                                                        }
                                                                        value={
                                                                            option.id
                                                                        }
                                                                    >
                                                                        {
                                                                            option.name
                                                                        }{' '}
                                                                        (
                                                                        {
                                                                            option.sku
                                                                        }
                                                                        )
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    ) : (
                                                        <span>
                                                            {
                                                                gift.options[0]
                                                                    ?.name
                                                            }
                                                        </span>
                                                    )}
                                                </div>
                                            ))}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
                <DialogFooter>
                    {choice.promotion_ids.length > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            className="mr-auto"
                            onClick={() => {
                                onApply({
                                    ...choice,
                                    promotion_ids: [],
                                    gifts: {},
                                });
                                onOpenChange(false);
                            }}
                        >
                            Bỏ khuyến mại
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Thoát
                    </Button>
                    <Button
                        type="button"
                        disabled={offers === null}
                        onClick={() => {
                            onApply({
                                ...choice,
                                promotion_ids: picked,
                                gifts,
                            });
                            onOpenChange(false);
                        }}
                    >
                        Áp dụng
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * "Mã giảm giá" in the order summary: type a code; the server checks it
 * with the rest of the order.
 */
export function CouponField({
    value,
    onChange,
    error,
    amount,
    className,
}: {
    value: string;
    onChange: (code: string) => void;
    error?: string;
    amount: number;
    className?: string;
}) {
    const [draft, setDraft] = useState(value);
    const [open, setOpen] = useState(value !== '');

    useEffect(() => setDraft(value), [value]);

    if (!open) {
        return (
            <div
                className={cn(
                    'flex items-center justify-between gap-3',
                    className,
                )}
            >
                <button
                    type="button"
                    className="text-primary hover:underline"
                    onClick={() => setOpen(true)}
                >
                    Mã giảm giá
                </button>
                <span className="tabular-nums">0</span>
            </div>
        );
    }

    return (
        <div className={cn('grid gap-1', className)}>
            <div className="flex items-center justify-between gap-3">
                <span>Mã giảm giá</span>
                <div className="flex items-center gap-1">
                    <Input
                        className="h-8 w-32 uppercase"
                        value={draft}
                        placeholder="Nhập mã"
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                onChange(draft.trim().toUpperCase());
                            }
                        }}
                        onBlur={() => {
                            if (draft.trim().toUpperCase() !== value) {
                                onChange(draft.trim().toUpperCase());
                            }
                        }}
                    />
                    <span className="w-24 text-right tabular-nums">
                        {amount > 0 ? `−${formatNumber(amount)}` : '0'}
                    </span>
                </div>
            </div>
            {error && value !== '' && (
                <p className="text-right text-xs text-destructive">{error}</p>
            )}
        </div>
    );
}

/** Applied programs under the "Áp dụng chương trình khuyến mại" link. */
export function AppliedPromotions({
    applied,
    errors,
    loading,
}: {
    applied: PromotionPreview['applied'];
    errors: Record<string, string>;
    loading: boolean;
}) {
    const programs = useMemo(
        () => applied.filter((item) => item.promotion_id !== null),
        [applied],
    );

    if (loading) {
        return (
            <p className="flex items-center gap-1 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" /> Đang tính khuyến
                mại…
            </p>
        );
    }

    return (
        <>
            {programs.map((item) => (
                <p
                    key={item.promotion_id}
                    className="flex items-center gap-1 text-xs text-success"
                >
                    <TicketPercent className="size-3" /> {item.name}
                    {item.amount > 0 ? ` (−${formatNumber(item.amount)})` : ''}
                </p>
            ))}
            {errors.promotion_ids && (
                <p className="text-xs text-destructive">
                    {errors.promotion_ids}
                </p>
            )}
        </>
    );
}
