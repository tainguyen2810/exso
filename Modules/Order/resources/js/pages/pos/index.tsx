import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Delete,
    Gift,
    History,
    LogOut,
    Minus,
    Plus,
    Printer,
    Store,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { toast } from 'sonner';
import { CustomerPicker } from '@/components/app/customer-picker';
import type { CustomerOption } from '@/components/app/customer-picker';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { PercentInput } from '@/components/app/percent-input';
import { ProductThumb } from '@/components/app/product-thumb';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatNumber } from '@/lib/format';
import { HttpError, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    OrderProductSearch,
    repriceVariants,
    searchVariants,
} from '../../components/order-product-search';
import type {
    SaleVariant,
    SearchHandle,
} from '../../components/order-product-search';
import type {
    PaymentMethodOption,
    QrInfo,
} from '../../components/payment-dialog';
import { Receipt, printCss } from '../../components/receipt';
import type {
    PrintableOrder,
    PrintSize,
    StoreInfo,
} from '../../components/receipt';
import {
    AppliedPromotions,
    CouponField,
    PromotionDialog,
    usePromotionPreview,
} from '../../components/promotions';
import type {
    PromotionChoice,
    PromotionRequest,
} from '../../components/promotions';
import { lineTotal, orderTotals } from '../../components/totals';
import type { PromotionSettings } from '../orders/form';

type Line = {
    variant_id: number;
    name: string;
    sku: string;
    unit: string | null;
    image_url: string | null;
    type: string;
    price: number;
    quantity: number;
    discount: number;
    available: number | null;
};

type Tab = {
    id: number;
    customer: CustomerOption | null;
    items: Line[];
    discount: number;
    discount_bp: number | null;
    note: string;
    methodId: number | null;
    cashGiven: number | null;
    /** Khuyến mại chosen (absent in tabs saved before G5). */
    promotion_ids?: number[];
    coupon_code?: string;
    gifts?: Record<string, number>;
};

type ShiftSummary = {
    cash_in: number;
    cash_out: number;
    expected_cash: number;
    orders_count: number;
    sales_total: number;
    by_method: { name: string; amount: number }[];
};

type Shift = ShiftSummary & {
    id: number;
    opened_at: string;
    opening_cash: number;
};

type Props = {
    branches: { id: number; name: string }[];
    branchId: number | null;
    shift: Shift | null;
    paymentMethods: PaymentMethodOption[];
    canEditPrice: boolean;
    defaultPosMethod: number | string | null;
    requirePaidAmount: boolean;
    storeInfo: StoreInfo;
    promotionSettings: PromotionSettings;
};

const MAX_TABS = 10;

/**
 * "Bán tại quầy": full screen, several order tabs, the scan box keeps the
 * focus (Enter on a barcode adds it), best sellers grid, keypad for the
 * cash given, VietQR for transfers and a thermal bill. F1 thanh toán,
 * F2 tiền khách đưa, F3 tìm sản phẩm, F4 khách hàng, F6 chiết khấu, F8 đơn mới.
 */
export default function Pos(props: Props) {
    const {
        branches,
        branchId,
        shift,
        paymentMethods,
        canEditPrice,
        defaultPosMethod,
        requirePaidAmount,
        storeInfo,
        promotionSettings,
    } = props;
    const { auth } = usePage().props;
    const storageKey = `exso.pos.${auth.user.id}.${branchId ?? 0}`;
    const defaultMethod =
        paymentMethods.find(
            (method) => String(method.id) === String(defaultPosMethod ?? ''),
        )?.id ??
        paymentMethods.find((method) => method.type === 'cash')?.id ??
        paymentMethods[0]?.id ??
        null;
    const blankTab = useCallback(
        (id: number): Tab => ({
            id,
            customer: null,
            items: [],
            discount: 0,
            discount_bp: null,
            note: '',
            methodId: defaultMethod,
            cashGiven: null,
        }),
        [defaultMethod],
    );

    const [tabs, setTabs] = useState<Tab[]>(() => {
        try {
            const saved = JSON.parse(
                localStorage.getItem(storageKey) ?? 'null',
            ) as Tab[] | null;

            if (Array.isArray(saved) && saved.length > 0) {
                return saved;
            }
        } catch {
            // Storage blocked or broken: start fresh.
        }

        return [blankTab(1)];
    });
    const [activeId, setActiveId] = useState(tabs[0].id);
    const [summary, setSummary] = useState<ShiftSummary | null>(shift);
    const [paying, setPaying] = useState(false);
    const [discountOpen, setDiscountOpen] = useState(false);
    const [promotionOpen, setPromotionOpen] = useState(false);
    const [closing, setClosing] = useState(false);
    const [printSize, setPrintSize] = useState<PrintSize>(() => {
        try {
            return (
                (localStorage.getItem('exso.pos.print') as PrintSize | null) ??
                '80'
            );
        } catch {
            return '80';
        }
    });
    const [autoPrint, setAutoPrint] = useState(true);
    const [receipt, setReceipt] = useState<PrintableOrder | null>(null);
    const [qr, setQr] = useState<QrInfo | null>(null);
    const [top, setTop] = useState<SaleVariant[]>([]);
    const search = useRef<SearchHandle>(null);
    const cashInput = useRef<HTMLInputElement>(null);

    const tab = tabs.find((item) => item.id === activeId) ?? tabs[0];
    const pricePolicy = tab.customer?.price_policy ?? 'retail_price';
    // Same server pricing of promotions as the order form.
    const promotionRequest: PromotionRequest = useMemo(
        () => ({
            pos: true,
            branch_id: branchId,
            customer_id: tab.customer?.id ?? null,
            price_policy: pricePolicy,
            items: tab.items.map((line) => ({
                variant_id: line.variant_id,
                quantity: line.quantity,
                price: line.price,
                discount: line.discount,
            })),
        }),
        [branchId, tab.customer, pricePolicy, tab.items],
    );
    const promotionChoice: PromotionChoice = {
        promotion_ids: tab.promotion_ids ?? [],
        coupon_code: tab.coupon_code ?? '',
        gifts: tab.gifts ?? {},
    };
    const promo = usePromotionPreview(promotionRequest, promotionChoice);
    const pricedItems = tab.items.map((line, index) => ({
        ...line,
        discount: line.discount + promo.lineDiscount(index),
    }));
    const couponAmount =
        promo.applied.find((item) => item.coupon_id !== null)?.amount ?? 0;
    const totals = orderTotals(
        pricedItems,
        tab.discount,
        tab.discount_bp,
        0,
        promo.orderDiscount,
    );
    const method =
        paymentMethods.find((item) => item.id === tab.methodId) ?? null;
    const isCash = method?.type === 'cash';
    const given = isCash
        ? (tab.cashGiven ?? (requirePaidAmount ? 0 : totals.total))
        : totals.total;
    const change = Math.max(0, given - totals.total);
    const short = Math.max(0, totals.total - given);

    useEffect(() => setSummary(shift), [shift]);

    useEffect(() => {
        try {
            localStorage.setItem(storageKey, JSON.stringify(tabs));
        } catch {
            // Not saved: the tabs only live in this page.
        }
    }, [tabs, storageKey]);

    useEffect(() => {
        if (branchId) {
            searchVariants({
                q: '',
                branchId,
                pricePolicy: 'retail_price',
                limit: 24,
                top: true,
            })
                .then(setTop)
                .catch(() => undefined);
        }
    }, [branchId]);

    // Dynamic VietQR for the amount on screen when paying by transfer.
    useEffect(() => {
        setQr(null);

        if (method?.type !== 'bank' || totals.total <= 0) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(
                `/pos/qr?payment_method_id=${method.id}&amount=${totals.total}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) => (response.ok ? response.json() : null))
                .then((data: QrInfo | null) => setQr(data?.svg ? data : null))
                .catch(() => undefined);
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [method?.id, method?.type, totals.total]);

    const update = (patch: Partial<Tab> | ((current: Tab) => Partial<Tab>)) =>
        setTabs((list) =>
            list.map((item) =>
                item.id === activeId
                    ? {
                          ...item,
                          ...(typeof patch === 'function'
                              ? patch(item)
                              : patch),
                      }
                    : item,
            ),
        );

    const addTab = () => {
        if (tabs.length >= MAX_TABS) {
            toast.warning(`Tối đa ${MAX_TABS} đơn cùng lúc.`);

            return;
        }

        const id = Math.max(...tabs.map((item) => item.id)) + 1;
        setTabs([...tabs, blankTab(id)]);
        setActiveId(id);
        setTimeout(() => search.current?.focus(), 0);
    };

    const closeTab = (id: number) => {
        const rest = tabs.filter((item) => item.id !== id);
        const next = rest.length > 0 ? rest : [blankTab(1)];
        setTabs(next);

        if (id === activeId) {
            setActiveId(next[next.length - 1].id);
        }
    };

    const addVariant = (variant: SaleVariant) => {
        update((current) => {
            const existing = current.items.find(
                (line) => line.variant_id === variant.id,
            );

            return {
                items: existing
                    ? current.items.map((line) =>
                          line.variant_id === variant.id
                              ? { ...line, quantity: line.quantity + 1 }
                              : line,
                      )
                    : [
                          ...current.items,
                          {
                              variant_id: variant.id,
                              name: variant.name,
                              sku: variant.sku,
                              unit: variant.unit,
                              image_url: variant.image_url,
                              type: variant.type,
                              price: variant.price,
                              quantity: 1,
                              discount: 0,
                              available: variant.available,
                          },
                      ],
            };
        });
        search.current?.focus();
    };

    const pickTopProduct = (variant: SaleVariant) => {
        if (pricePolicy === 'retail_price') {
            addVariant(variant);

            return;
        }

        void repriceVariants([variant.id], pricePolicy).then((prices) =>
            addVariant({
                ...variant,
                price: prices[variant.id] ?? variant.price,
            }),
        );
    };

    const setLine = (variantId: number, patch: Partial<Line>) =>
        update((current) => ({
            items: current.items.map((line) =>
                line.variant_id === variantId ? { ...line, ...patch } : line,
            ),
        }));

    const pickCustomer = (customer: CustomerOption | null) => {
        const policy = customer?.price_policy ?? 'retail_price';
        update({
            customer,
            discount_bp:
                customer && customer.discount_bp > 0
                    ? customer.discount_bp
                    : tab.discount_bp,
            methodId: customer?.payment_method_id ?? tab.methodId,
        });

        if (policy !== pricePolicy) {
            void repriceVariants(
                tab.items.map((line) => line.variant_id),
                policy,
            ).then((prices) =>
                update((current) => ({
                    items: current.items.map((line) => ({
                        ...line,
                        price: prices[line.variant_id] ?? line.price,
                    })),
                })),
            );
        }

        search.current?.focus();
    };

    const pay = () => {
        if (paying || !summary) {
            return;
        }

        if (promo.loading) {
            toast.warning('Đang tính khuyến mại, thử lại sau giây lát.');

            return;
        }

        if (tab.items.length === 0) {
            toast.warning('Đơn hàng chưa có sản phẩm.');
            search.current?.focus();

            return;
        }

        if (!method) {
            toast.warning('Hãy chọn hình thức thanh toán.');

            return;
        }

        if (short > 0 && !tab.customer) {
            toast.warning(
                'Khách lẻ phải thanh toán đủ. Chọn khách hàng (F4) để ghi nợ phần còn lại.',
            );
            cashInput.current?.focus();

            return;
        }

        setPaying(true);
        postJson<{
            order: { id: number; code: string };
            print: PrintableOrder;
            shift: ShiftSummary;
        }>('/pos/orders', {
            branch_id: branchId,
            customer_id: tab.customer?.id ?? null,
            price_policy: pricePolicy,
            discount: tab.discount,
            discount_bp: tab.discount_bp,
            note: tab.note || null,
            items: tab.items.map((line) => ({
                variant_id: line.variant_id,
                quantity: line.quantity,
                price: line.price,
                discount: line.discount,
            })),
            promotion_ids: promotionChoice.promotion_ids,
            coupon_code: promotionChoice.coupon_code || null,
            gifts: promotionChoice.gifts,
            payments:
                given > 0
                    ? [
                          {
                              payment_method_id: method.id,
                              amount: Math.min(given, totals.total),
                          },
                      ]
                    : [],
        })
            .then((result) => {
                toast.success(
                    `Đã bán đơn ${result.order.code}${change > 0 ? `, trả lại khách ${formatNumber(change)} đ` : ''}.`,
                );
                setSummary(result.shift);

                if (autoPrint) {
                    setReceipt({
                        ...result.print,
                        cash_given: isCash ? given : null,
                        change: isCash ? change : null,
                    });
                }

                setTabs((list) =>
                    list.map((item) =>
                        item.id === activeId ? blankTab(item.id) : item,
                    ),
                );
                search.current?.focus();
            })
            .catch((error: unknown) => {
                if (error instanceof HttpError) {
                    toast.error(
                        Object.values(error.errors)[0] ?? error.message,
                    );
                } else {
                    toast.error('Không bán được, hãy thử lại.');
                }
            })
            .finally(() => setPaying(false));
    };

    useEffect(() => {
        if (receipt) {
            const timer = setTimeout(() => {
                window.print();
                setReceipt(null);
            }, 100);

            return () => clearTimeout(timer);
        }
    }, [receipt]);

    const payRef = useRef(pay);
    payRef.current = pay;

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F1') {
                event.preventDefault();
                payRef.current();
            } else if (event.key === 'F2') {
                event.preventDefault();
                cashInput.current?.focus();
            } else if (event.key === 'F6') {
                event.preventDefault();
                setDiscountOpen(true);
            } else if (event.key === 'F8') {
                event.preventDefault();
                document.getElementById('pos-new-tab')?.click();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const suggestions = useMemo(() => {
        const total = totals.total;

        if (total <= 0) {
            return [];
        }

        const values = [
            total,
            ...[10000, 50000, 100000, 200000, 500000].map(
                (step) => Math.ceil(total / step) * step,
            ),
        ];

        return [...new Set(values)]
            .filter((value) => value >= total)
            .slice(0, 5);
    }, [totals.total]);

    const press = (key: string) => {
        const current = tab.cashGiven === null ? '' : String(tab.cashGiven);
        const next =
            key === 'back'
                ? current.slice(0, -1)
                : key === 'clear'
                  ? ''
                  : `${current}${key}`;
        update({
            cashGiven:
                next === '' ? null : Math.min(Number(next), 999_999_999_999),
        });
    };

    const branchName =
        branches.find((branch) => branch.id === branchId)?.name ?? '';

    return (
        <>
            <Head title="Bán tại quầy" />
            <style>{`${printCss(printSize)} @media print { .pos-screen { display: none !important; } }`}</style>
            <div className="pos-screen flex h-dvh flex-col bg-[#F0F1F1] text-sm">
                <header className="flex h-12 shrink-0 items-center gap-2 bg-sidebar px-3 text-sidebar-foreground">
                    <span className="mr-1 flex items-center gap-1.5 font-bold">
                        <Store className="size-5" /> EXSO
                    </span>
                    <OrderProductSearch
                        ref={search}
                        className="w-[28rem] max-w-[40vw] text-foreground [&_input]:bg-white"
                        onPick={addVariant}
                        branchId={branchId}
                        pricePolicy={pricePolicy}
                        autoFocus
                        placeholder="Quét mã vạch hoặc tìm sản phẩm (F3)"
                    />
                    <nav className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
                        {tabs.map((item, index) => (
                            <span
                                key={item.id}
                                className={cn(
                                    'flex h-8 shrink-0 items-center gap-1 rounded-md pr-1 pl-3',
                                    item.id === activeId
                                        ? 'bg-white text-foreground'
                                        : 'bg-white/10 hover:bg-white/20',
                                )}
                            >
                                <button
                                    type="button"
                                    onClick={() => setActiveId(item.id)}
                                >
                                    Đơn {index + 1}
                                    {item.items.length > 0 && (
                                        <span className="ml-1 text-xs opacity-70">
                                            ({item.items.length})
                                        </span>
                                    )}
                                </button>
                                <button
                                    type="button"
                                    aria-label={`Đóng đơn ${index + 1}`}
                                    className="rounded p-0.5 opacity-60 hover:opacity-100"
                                    onClick={() => closeTab(item.id)}
                                >
                                    <X className="size-3.5" />
                                </button>
                            </span>
                        ))}
                        <button
                            id="pos-new-tab"
                            type="button"
                            title="Thêm đơn mới (F8)"
                            className="flex size-8 shrink-0 items-center justify-center rounded-md bg-white/10 hover:bg-white/20"
                            onClick={addTab}
                        >
                            <Plus className="size-4" />
                        </button>
                    </nav>
                    <RecentOrders />
                    <span className="hidden text-xs opacity-80 lg:inline">
                        {branchName} · {auth.user.name}
                    </span>
                    {summary && shift && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => setClosing(true)}
                        >
                            Chốt ca
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="ghost"
                        className="text-sidebar-foreground hover:bg-white/10 hover:text-white"
                        asChild
                    >
                        <Link href="/orders">
                            <LogOut className="size-4" /> Thoát
                        </Link>
                    </Button>
                </header>

                <div className="grid min-h-0 flex-1 grid-cols-1 gap-3 p-3 lg:grid-cols-[1fr_400px]">
                    <section className="flex min-h-0 flex-col gap-3">
                        <div className="min-h-0 flex-1 overflow-y-auto rounded-lg bg-white">
                            {tab.items.length === 0 ? (
                                <div className="flex h-full flex-col items-center justify-center gap-1 p-6 text-center text-muted-foreground">
                                    <span className="text-base">
                                        Quét mã vạch hoặc tìm sản phẩm để thêm
                                        vào đơn
                                    </span>
                                    <span className="text-xs">
                                        F1 thanh toán · F2 tiền khách đưa · F3
                                        tìm sản phẩm · F4 khách hàng · F6 chiết
                                        khấu · F8 đơn mới
                                    </span>
                                </div>
                            ) : (
                                <table className="w-full">
                                    <thead className="sticky top-0 bg-white text-left text-xs text-muted-foreground">
                                        <tr className="border-b">
                                            <th className="w-8 px-3 py-2 font-normal">
                                                #
                                            </th>
                                            <th className="py-2 font-normal">
                                                Sản phẩm
                                            </th>
                                            <th className="w-36 py-2 text-center font-normal">
                                                Số lượng
                                            </th>
                                            <th className="w-32 py-2 text-right font-normal">
                                                Đơn giá
                                            </th>
                                            <th className="w-32 py-2 text-right font-normal">
                                                Thành tiền
                                            </th>
                                            <th className="w-10" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {tab.items.map((line, index) => (
                                            <tr
                                                key={line.variant_id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="px-3 py-2 text-muted-foreground">
                                                    {index + 1}
                                                </td>
                                                <td className="py-2">
                                                    <div className="flex items-center gap-2">
                                                        <ProductThumb
                                                            url={line.image_url}
                                                            className="size-9"
                                                        />
                                                        <div className="min-w-0">
                                                            <div className="truncate font-medium">
                                                                {line.name}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {line.sku}
                                                                {line.available !=
                                                                    null &&
                                                                    line.type !==
                                                                        'service' && (
                                                                        <span
                                                                            className={cn(
                                                                                line.quantity >
                                                                                    line.available &&
                                                                                    'text-warning',
                                                                            )}
                                                                        >
                                                                            {' '}
                                                                            · Có
                                                                            thể
                                                                            bán{' '}
                                                                            {formatNumber(
                                                                                line.available,
                                                                            )}
                                                                        </span>
                                                                    )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="py-2">
                                                    <div className="flex items-center justify-center gap-1">
                                                        <Button
                                                            size="icon"
                                                            variant="outline"
                                                            className="size-7"
                                                            tabIndex={-1}
                                                            aria-label="Giảm"
                                                            onClick={() =>
                                                                line.quantity >
                                                                1
                                                                    ? setLine(
                                                                          line.variant_id,
                                                                          {
                                                                              quantity:
                                                                                  line.quantity -
                                                                                  1,
                                                                          },
                                                                      )
                                                                    : update(
                                                                          (
                                                                              current,
                                                                          ) => ({
                                                                              items: current.items.filter(
                                                                                  (
                                                                                      item,
                                                                                  ) =>
                                                                                      item.variant_id !==
                                                                                      line.variant_id,
                                                                              ),
                                                                          }),
                                                                      )
                                                            }
                                                        >
                                                            <Minus className="size-3" />
                                                        </Button>
                                                        <MoneyInput
                                                            className="h-7 w-14 px-1 text-center"
                                                            value={
                                                                line.quantity
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                setLine(
                                                                    line.variant_id,
                                                                    {
                                                                        quantity:
                                                                            Math.max(
                                                                                1,
                                                                                value ??
                                                                                    1,
                                                                            ),
                                                                    },
                                                                )
                                                            }
                                                            onKeyDown={(
                                                                event,
                                                            ) =>
                                                                event.key ===
                                                                    'Enter' &&
                                                                search.current?.focus()
                                                            }
                                                        />
                                                        <Button
                                                            size="icon"
                                                            variant="outline"
                                                            className="size-7"
                                                            tabIndex={-1}
                                                            aria-label="Tăng"
                                                            onClick={() =>
                                                                setLine(
                                                                    line.variant_id,
                                                                    {
                                                                        quantity:
                                                                            line.quantity +
                                                                            1,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <Plus className="size-3" />
                                                        </Button>
                                                    </div>
                                                </td>
                                                <td className="py-2">
                                                    {canEditPrice ? (
                                                        <MoneyInput
                                                            className="ml-auto h-7 w-28"
                                                            value={line.price}
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                setLine(
                                                                    line.variant_id,
                                                                    {
                                                                        price:
                                                                            value ??
                                                                            0,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    ) : (
                                                        <div className="text-right tabular-nums">
                                                            {formatNumber(
                                                                line.price,
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="py-2 text-right font-medium tabular-nums">
                                                    {formatNumber(
                                                        lineTotal(
                                                            pricedItems[index],
                                                        ),
                                                    )}
                                                    {promo.lineDiscount(index) >
                                                        0 && (
                                                        <div className="text-xs font-normal text-success">
                                                            KM −
                                                            {formatNumber(
                                                                promo.lineDiscount(
                                                                    index,
                                                                ),
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="py-2 pr-2 text-right">
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-7 text-muted-foreground"
                                                        tabIndex={-1}
                                                        aria-label="Xóa sản phẩm"
                                                        onClick={() =>
                                                            update(
                                                                (current) => ({
                                                                    items: current.items.filter(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item.variant_id !==
                                                                            line.variant_id,
                                                                    ),
                                                                }),
                                                            )
                                                        }
                                                    >
                                                        <X className="size-4" />
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                        {promo.gifts.map((gift) => (
                                            <tr
                                                key={`gift-${gift.promotion_id}-${gift.variant_id}`}
                                                className="border-b bg-success/5 last:border-0"
                                            >
                                                <td className="py-2 pl-2">
                                                    <Gift className="size-4 text-success" />
                                                </td>
                                                <td className="py-2">
                                                    <div className="font-medium">
                                                        {gift.name}
                                                    </div>
                                                    <div className="text-xs text-success">
                                                        Quà tặng · {gift.sku}
                                                    </div>
                                                </td>
                                                <td className="py-2 text-center tabular-nums">
                                                    {formatNumber(
                                                        gift.quantity,
                                                    )}
                                                </td>
                                                <td className="py-2 text-right tabular-nums">
                                                    0
                                                </td>
                                                <td className="py-2 text-right font-medium tabular-nums">
                                                    0
                                                </td>
                                                <td />
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                        {top.length > 0 && (
                            <div className="max-h-48 shrink-0 overflow-y-auto rounded-lg bg-white p-2">
                                <div className="mb-1 px-1 text-xs font-medium text-muted-foreground">
                                    Sản phẩm bán chạy
                                </div>
                                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 xl:grid-cols-6">
                                    {top.map((variant) => (
                                        <button
                                            key={variant.id}
                                            type="button"
                                            tabIndex={-1}
                                            className="flex items-center gap-2 rounded-md border p-1.5 text-left hover:border-primary"
                                            onClick={() =>
                                                pickTopProduct(variant)
                                            }
                                        >
                                            <ProductThumb
                                                url={variant.image_url}
                                                className="size-9 shrink-0"
                                            />
                                            <span className="min-w-0">
                                                <span className="line-clamp-2 text-xs">
                                                    {variant.name}
                                                </span>
                                                <span className="text-xs font-medium text-primary tabular-nums">
                                                    {formatNumber(
                                                        variant.price,
                                                    )}
                                                </span>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>

                    <aside className="flex min-h-0 flex-col gap-3 overflow-y-auto rounded-lg bg-white p-4">
                        <CustomerPicker
                            value={tab.customer}
                            onChange={pickCustomer}
                            compact
                            placeholder="Khách lẻ · tìm khách hàng (F4)"
                        />
                        <div className="grid gap-1.5 border-t pt-3">
                            {promotionSettings.enabled && (
                                <div className="grid gap-1">
                                    <button
                                        type="button"
                                        tabIndex={-1}
                                        className="inline-flex items-center gap-1.5 self-start text-sm text-primary hover:underline"
                                        onClick={() => setPromotionOpen(true)}
                                    >
                                        <Gift className="size-4" /> Áp dụng
                                        chương trình khuyến mại
                                    </button>
                                    <AppliedPromotions
                                        applied={promo.applied}
                                        errors={promo.errors}
                                        loading={promo.loading}
                                    />
                                </div>
                            )}
                            <Row
                                label={`Tổng tiền hàng (${formatNumber(totals.quantity)} sp)`}
                                value={totals.subtotal}
                            />
                            <div className="flex justify-between">
                                <button
                                    type="button"
                                    className="text-primary hover:underline"
                                    onClick={() => setDiscountOpen(true)}
                                >
                                    Chiết khấu (F6)
                                    {tab.discount_bp
                                        ? ` ${String(tab.discount_bp / 100).replace('.', ',')}%`
                                        : ''}
                                </button>
                                <span className="tabular-nums">
                                    {formatNumber(
                                        totals.discount - couponAmount,
                                    )}
                                </span>
                            </div>
                            {promotionSettings.coupons && (
                                <CouponField
                                    value={tab.coupon_code ?? ''}
                                    onChange={(code) =>
                                        update({ coupon_code: code })
                                    }
                                    error={promo.errors.coupon_code}
                                    amount={couponAmount}
                                />
                            )}
                            <div className="flex justify-between text-lg font-semibold">
                                <span>Khách phải trả</span>
                                <span className="text-primary tabular-nums">
                                    {formatNumber(totals.total)}
                                </span>
                            </div>
                        </div>
                        <div className="grid gap-2 border-t pt-3">
                            <span className="text-xs text-muted-foreground">
                                Hình thức thanh toán
                            </span>
                            <div className="flex flex-wrap gap-1.5">
                                {paymentMethods.map((item) => (
                                    <Button
                                        key={item.id}
                                        size="sm"
                                        tabIndex={-1}
                                        variant={
                                            item.id === tab.methodId
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            update({ methodId: item.id })
                                        }
                                    >
                                        {item.name}
                                    </Button>
                                ))}
                            </div>
                        </div>
                        {isCash ? (
                            <div className="grid gap-2">
                                <Field label="Tiền khách đưa (F2)">
                                    <MoneyInput
                                        ref={cashInput}
                                        className="h-10 text-lg"
                                        value={tab.cashGiven}
                                        placeholder={formatNumber(totals.total)}
                                        onValueChange={(value) =>
                                            update({ cashGiven: value })
                                        }
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                pay();
                                            }
                                        }}
                                    />
                                </Field>
                                <div className="flex flex-wrap gap-1.5">
                                    {suggestions.map((value) => (
                                        <Button
                                            key={value}
                                            size="sm"
                                            variant="outline"
                                            tabIndex={-1}
                                            onClick={() =>
                                                update({ cashGiven: value })
                                            }
                                        >
                                            {formatNumber(value)}
                                        </Button>
                                    ))}
                                </div>
                                <div className="grid grid-cols-4 gap-1.5">
                                    {[
                                        '7',
                                        '8',
                                        '9',
                                        'back',
                                        '4',
                                        '5',
                                        '6',
                                        'clear',
                                        '1',
                                        '2',
                                        '3',
                                        '000',
                                        '0',
                                        '00',
                                    ].map((key) => (
                                        <Button
                                            key={key}
                                            variant="outline"
                                            tabIndex={-1}
                                            className={cn(
                                                'h-10 text-base',
                                                key === '0' && 'col-span-2',
                                            )}
                                            onClick={() => press(key)}
                                        >
                                            {key === 'back' ? (
                                                <Delete className="size-4" />
                                            ) : key === 'clear' ? (
                                                'C'
                                            ) : (
                                                key
                                            )}
                                        </Button>
                                    ))}
                                </div>
                                <div className="flex justify-between text-base">
                                    <span>
                                        {short > 0
                                            ? tab.customer
                                                ? 'Ghi nợ khách'
                                                : 'Còn thiếu'
                                            : 'Tiền thừa trả khách'}
                                    </span>
                                    <span
                                        className={cn(
                                            'font-semibold tabular-nums',
                                            short > 0 && 'text-destructive',
                                        )}
                                    >
                                        {formatNumber(
                                            short > 0 ? short : change,
                                        )}
                                    </span>
                                </div>
                            </div>
                        ) : (
                            method?.type === 'bank' && (
                                <div className="flex flex-col items-center gap-1 rounded-md border p-3 text-center text-xs">
                                    {qr ? (
                                        <>
                                            <div
                                                className="size-44 [&>svg]:size-full"
                                                dangerouslySetInnerHTML={{
                                                    __html: qr.svg,
                                                }}
                                            />
                                            <span className="font-medium">
                                                {qr.bank} · {qr.account_number}
                                            </span>
                                            {qr.account_name && (
                                                <span>{qr.account_name}</span>
                                            )}
                                            <span>
                                                Số tiền:{' '}
                                                {formatNumber(qr.amount)} đ
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {totals.total > 0
                                                ? 'Hình thức này chưa có số tài khoản ngân hàng để tạo VietQR (Cấu hình → Hình thức thanh toán).'
                                                : 'Mã VietQR hiện khi đơn có sản phẩm.'}
                                        </span>
                                    )}
                                </div>
                            )
                        )}
                        <Textarea
                            className="min-h-14"
                            value={tab.note}
                            placeholder="Ghi chú đơn hàng"
                            onChange={(event) =>
                                update({ note: event.target.value })
                            }
                        />
                        <div className="mt-auto grid gap-2">
                            <div className="flex items-center justify-between gap-2 text-xs">
                                <label className="flex items-center gap-1.5">
                                    <input
                                        type="checkbox"
                                        checked={autoPrint}
                                        onChange={(event) =>
                                            setAutoPrint(event.target.checked)
                                        }
                                    />
                                    <Printer className="size-3.5" /> In hóa đơn
                                </label>
                                <span className="flex gap-1">
                                    {(['80', '58', 'a5'] as const).map(
                                        (size) => (
                                            <button
                                                key={size}
                                                type="button"
                                                tabIndex={-1}
                                                className={cn(
                                                    'rounded border px-1.5 py-0.5',
                                                    printSize === size &&
                                                        'border-primary text-primary',
                                                )}
                                                onClick={() => {
                                                    setPrintSize(size);

                                                    try {
                                                        localStorage.setItem(
                                                            'exso.pos.print',
                                                            size,
                                                        );
                                                    } catch {
                                                        // Remembered for this page only.
                                                    }
                                                }}
                                            >
                                                {size === 'a5'
                                                    ? 'A5'
                                                    : `K${size}`}
                                            </button>
                                        ),
                                    )}
                                </span>
                            </div>
                            <Button
                                className="h-12 text-base"
                                disabled={
                                    paying || !summary || tab.items.length === 0
                                }
                                onClick={pay}
                            >
                                Thanh toán (F1)
                            </Button>
                        </div>
                    </aside>
                </div>
            </div>

            {receipt &&
                createPortal(
                    <div className="hidden print:block">
                        <Receipt
                            order={receipt}
                            store={storeInfo}
                            size={printSize}
                        />
                    </div>,
                    document.body,
                )}

            <PromotionDialog
                open={promotionOpen}
                onOpenChange={setPromotionOpen}
                request={promotionRequest}
                choice={promotionChoice}
                multiple={promotionSettings.multiple}
                onApply={(choice) => {
                    update({
                        promotion_ids: choice.promotion_ids,
                        gifts: choice.gifts,
                    });
                    search.current?.focus();
                }}
            />
            <DiscountDialog
                open={discountOpen}
                onOpenChange={setDiscountOpen}
                discount={tab.discount}
                discountBp={tab.discount_bp}
                subtotal={totals.subtotal}
                onApply={(discount, discountBp) => {
                    update({ discount, discount_bp: discountBp });
                    search.current?.focus();
                }}
            />
            {!shift && (
                <OpenShiftDialog branchId={branchId} branches={branches} />
            )}
            {shift && summary && (
                <CloseShiftDialog
                    open={closing}
                    onOpenChange={setClosing}
                    shift={shift}
                    summary={summary}
                />
            )}
        </>
    );
}

function Row({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex justify-between">
            <span>{label}</span>
            <span className="tabular-nums">{formatNumber(value)}</span>
        </div>
    );
}

function DiscountDialog({
    open,
    onOpenChange,
    discount,
    discountBp,
    subtotal,
    onApply,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    discount: number;
    discountBp: number | null;
    subtotal: number;
    onApply: (discount: number, discountBp: number | null) => void;
}) {
    const [percent, setPercent] = useState(false);
    const [amount, setAmount] = useState(0);
    const [bp, setBp] = useState<number | null>(null);

    useEffect(() => {
        if (open) {
            setPercent(discountBp !== null);
            setAmount(discount);
            setBp(discountBp);
        }
    }, [open, discount, discountBp]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-sm">
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onApply(
                            percent ? 0 : Math.min(amount, subtotal),
                            percent ? (bp ?? 0) : null,
                        );
                        onOpenChange(false);
                    }}
                >
                    <DialogTitle>Chiết khấu đơn hàng</DialogTitle>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant={percent ? 'outline' : 'default'}
                            onClick={() => setPercent(false)}
                        >
                            Giá trị (đ)
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={percent ? 'default' : 'outline'}
                            onClick={() => setPercent(true)}
                        >
                            Phần trăm (%)
                        </Button>
                    </div>
                    {percent ? (
                        <PercentInput
                            autoFocus
                            value={bp}
                            onValueChange={setBp}
                        />
                    ) : (
                        <MoneyInput
                            autoFocus
                            value={amount}
                            onValueChange={(value) => setAmount(value ?? 0)}
                        />
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Hủy
                        </Button>
                        <Button>Áp dụng</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** No open shift: the cashier counts the drawer before selling. */
function OpenShiftDialog({
    branchId,
    branches,
}: {
    branchId: number | null;
    branches: { id: number; name: string }[];
}) {
    const [cash, setCash] = useState<number | null>(0);
    const [processing, setProcessing] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <Dialog open>
            <DialogContent
                className="sm:max-w-sm [&>button:last-child]:hidden"
                onEscapeKeyDown={(event) => event.preventDefault()}
                onInteractOutside={(event) => event.preventDefault()}
            >
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setProcessing(true);
                        router.post(
                            '/pos/shifts',
                            { branch_id: branchId, opening_cash: cash ?? 0 },
                            { onFinish: () => setProcessing(false) },
                        );
                    }}
                >
                    <div>
                        <DialogTitle>Mở ca làm việc</DialogTitle>
                        <DialogDescription className="mt-1">
                            Chi nhánh{' '}
                            {branches.find((branch) => branch.id === branchId)
                                ?.name ?? ''}
                            . Nhập số tiền mặt có trong két khi bắt đầu ca.
                        </DialogDescription>
                    </div>
                    <Field
                        label="Tiền đầu ca"
                        error={
                            errors.opening_cash ??
                            errors.branch_id ??
                            errors.shift
                        }
                    >
                        <MoneyInput
                            autoFocus
                            className="h-10 text-lg"
                            value={cash}
                            onValueChange={setCash}
                        />
                    </Field>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" asChild>
                            <Link href="/orders">Thoát</Link>
                        </Button>
                        <Button disabled={processing || !branchId}>
                            Mở ca
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Chốt ca: compare the counted cash with the expected drawer. */
function CloseShiftDialog({
    open,
    onOpenChange,
    shift,
    summary,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shift: Shift;
    summary: ShiftSummary;
}) {
    const [counted, setCounted] = useState<number | null>(null);
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const difference = (counted ?? 0) - summary.expected_cash;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setProcessing(true);
                        router.post(
                            `/pos/shifts/${shift.id}/close`,
                            { counted_cash: counted ?? 0, note },
                            {
                                onFinish: () => setProcessing(false),
                                onSuccess: () => onOpenChange(false),
                            },
                        );
                    }}
                >
                    <div>
                        <DialogTitle>Chốt ca</DialogTitle>
                        <DialogDescription className="mt-1">
                            Mở ca lúc {formatDateTime(shift.opened_at)}.
                        </DialogDescription>
                    </div>
                    <div className="grid gap-1.5 text-sm">
                        <Row
                            label="Số đơn đã bán"
                            value={summary.orders_count}
                        />
                        <Row
                            label="Doanh thu trong ca"
                            value={summary.sales_total}
                        />
                        {summary.by_method.map((item) => (
                            <Row
                                key={item.name}
                                label={`· ${item.name}`}
                                value={item.amount}
                            />
                        ))}
                        <div className="my-1 border-t" />
                        <Row label="Tiền đầu ca" value={shift.opening_cash} />
                        <Row label="Tiền mặt thu" value={summary.cash_in} />
                        <Row
                            label="Tiền mặt chi (hoàn)"
                            value={-summary.cash_out}
                        />
                        <div className="flex justify-between font-semibold">
                            <span>Tiền mặt dự kiến trong két</span>
                            <span className="tabular-nums">
                                {formatNumber(summary.expected_cash)}
                            </span>
                        </div>
                    </div>
                    <Field label="Tiền mặt thực tế *">
                        <MoneyInput
                            autoFocus
                            className="h-10 text-lg"
                            value={counted}
                            onValueChange={setCounted}
                        />
                    </Field>
                    {counted !== null && (
                        <p
                            className={cn(
                                'text-sm',
                                difference === 0
                                    ? 'text-success'
                                    : 'text-destructive',
                            )}
                        >
                            {difference === 0
                                ? 'Khớp với tiền dự kiến.'
                                : `Chênh lệch ${difference > 0 ? '+' : ''}${formatNumber(difference)} đ.`}
                        </p>
                    )}
                    <Textarea
                        value={note}
                        placeholder="Ghi chú (lý do chênh lệch...)"
                        onChange={(event) => setNote(event.target.value)}
                    />
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Thoát
                        </Button>
                        <Button disabled={processing || counted === null}>
                            Chốt ca
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Sales of this shift, to open or reprint one. */
function RecentOrders() {
    const [orders, setOrders] = useState<
        {
            id: number;
            code: string;
            customer: string;
            total: number;
            sale_at: string | null;
        }[]
    >([]);

    return (
        <Popover
            onOpenChange={(open) => {
                if (open) {
                    fetch('/pos/recent', {
                        headers: { Accept: 'application/json' },
                    })
                        .then((response) =>
                            response.ok ? response.json() : [],
                        )
                        .then(setOrders)
                        .catch(() => undefined);
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    size="sm"
                    variant="ghost"
                    className="text-sidebar-foreground hover:bg-white/10 hover:text-white"
                    title="Đơn trong ca"
                >
                    <History className="size-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-96 p-0">
                <div className="border-b px-3 py-2 text-sm font-medium">
                    Đơn đã bán trong ca
                </div>
                {orders.length === 0 ? (
                    <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                        Chưa có đơn nào.
                    </p>
                ) : (
                    <ul className="max-h-80 divide-y overflow-y-auto text-sm">
                        {orders.map((order) => (
                            <li
                                key={order.id}
                                className="flex items-center gap-2 px-3 py-2"
                            >
                                <a
                                    href={`/orders/${order.id}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-primary hover:underline"
                                >
                                    {order.code}
                                </a>
                                <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                    {order.customer}
                                </span>
                                <span className="tabular-nums">
                                    {formatNumber(order.total)}
                                </span>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7"
                                    aria-label="In lại"
                                    onClick={() =>
                                        window.open(
                                            `/orders/${order.id}/print?size=80`,
                                            '_blank',
                                        )
                                    }
                                >
                                    <Printer className="size-4" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </PopoverContent>
        </Popover>
    );
}
