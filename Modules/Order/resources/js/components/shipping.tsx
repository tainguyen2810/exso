import { router } from '@inertiajs/react';
import { Loader2, Truck } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import type { SelectOption } from '@/components/app/option-select';
import type { StatusMap } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatNumber } from '@/lib/format';
import { HttpError, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';

/** Partners and package defaults sent by the shipping module (G6). */
export type ShippingOptions = {
    enabled: boolean;
    canCreate?: boolean;
    carriers?: { id: number; name: string; driver: string }[];
    partners?: { id: number; name: string }[];
    requiredNotes?: SelectOption[];
    feePayers?: SelectOption[];
    package?: {
        weight: number;
        length: number;
        width: number;
        height: number;
        required_note: string;
        note: string;
        fee_payer: string;
    };
};

export type ShipmentSummary = {
    id: number;
    code: string;
    tracking_code: string | null;
    status: string;
    carrier: string;
    integrated: boolean;
    carrier_status: string | null;
    service_name: string | null;
    cod_amount: number;
    fee: number;
    fee_payer: string;
    weight: number;
    size: string | null;
    required_note: string | null;
    note: string | null;
    cod_status: string;
    receiver_name: string;
    receiver_phone: string;
    address: string;
    created_at: string | null;
    events: {
        id: number;
        status: string | null;
        note: string | null;
        occurred_at: string;
    }[];
};

/** Shipment status as Sapo names it. */
export const shipmentStatuses: StatusMap = {
    pending: ['Chờ lấy hàng', 'warning'],
    shipping: ['Đang giao hàng', 'info'],
    redelivery: ['Chờ giao lại', 'warning'],
    delivered: ['Đã giao hàng', 'success'],
    returning: ['Hủy giao - chờ nhận', 'danger'],
    returned: ['Hủy giao - đã nhận', 'neutral'],
    cancelled: ['Hủy đóng gói', 'neutral'],
};

export const codStatuses: StatusMap = {
    none: ['Không thu hộ', 'neutral'],
    collecting: ['Chờ thu hộ COD', 'warning'],
    waiting: ['Chờ đối soát', 'warning'],
    reconciling: ['Đang đối soát', 'info'],
    reconciled: ['Đã đối soát', 'success'],
};

export const ACTIVE_SHIPMENT = [
    'pending',
    'shipping',
    'redelivery',
    'returning',
];

export type ShipmentDraft = {
    carrier_id: number | null;
    service_id: string | null;
    service_name: string | null;
    fee: number | null;
    cod_amount: number;
    insurance_value: number | null;
    fee_payer: string;
    weight: number;
    length: number;
    width: number;
    height: number;
    ward: string;
    district: string;
    province: string;
    required_note: string;
    note: string;
    tracking_code: string;
};

export const emptyShipment = (
    options: ShippingOptions,
    cod: number,
): ShipmentDraft => ({
    carrier_id: null,
    service_id: null,
    service_name: null,
    fee: null,
    cod_amount: cod,
    insurance_value: null,
    fee_payer: options.package?.fee_payer ?? 'shop',
    weight: options.package?.weight ?? 500,
    length: options.package?.length ?? 10,
    width: options.package?.width ?? 10,
    height: options.package?.height ?? 10,
    ward: '',
    district: '',
    province: '',
    required_note: options.package?.required_note ?? 'CHOXEMHANGKHONGTHU',
    note: options.package?.note ?? '',
    tracking_code: '',
});

type Quote = {
    carrier_id: number;
    carrier: string;
    service_id: string | null;
    service_name: string | null;
    fee: number | null;
    expected_at: string | null;
    error: string | null;
};

export type Receiver = {
    receiver_name: string;
    receiver_phone: string;
    address: string;
};

/**
 * Package and partner of a shipment: "carrier" picks a connected carrier's
 * service from its fees ("Chọn gói cước"), "external" a partner the store
 * contacts itself with the fee agreed with them.
 */
export function ShipmentFields({
    mode,
    options,
    value,
    onChange,
    receiver,
    scope,
    errors = {},
}: {
    mode: 'carrier' | 'external';
    options: ShippingOptions;
    value: ShipmentDraft;
    onChange: (value: ShipmentDraft) => void;
    receiver: Receiver;
    /** Where the parcel leaves from: the order, or the branch of a new one. */
    scope: { order_id?: number; branch_id?: number | null };
    errors?: Record<string, string | undefined>;
}) {
    const [quotes, setQuotes] = useState<Quote[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [quoteError, setQuoteError] = useState<string | null>(null);
    const set = (patch: Partial<ShipmentDraft>) =>
        onChange({ ...value, ...patch });
    const error = (key: string) => errors[`shipment.${key}`] ?? errors[key];

    const loadQuotes = async () => {
        setLoading(true);
        setQuoteError(null);

        try {
            const result = await postJson<{ quotes: Quote[] }>(
                '/shipping/quotes',
                { ...scope, ...receiver, ...value, carrier_id: 0 },
            );
            setQuotes(result.quotes);

            const best = result.quotes.find((quote) => quote.error === null);

            if (best && !value.service_id) {
                set({
                    carrier_id: best.carrier_id,
                    service_id: best.service_id,
                    service_name: best.service_name,
                    fee: best.fee,
                });
            }
        } catch (exception) {
            setQuotes(null);
            setQuoteError(
                exception instanceof HttpError
                    ? exception.message
                    : 'Không lấy được phí vận chuyển.',
            );
        } finally {
            setLoading(false);
        }
    };

    const size = (key: 'length' | 'width' | 'height', label: string) => (
        <MoneyInput
            aria-label={label}
            placeholder={label}
            value={value[key]}
            onValueChange={(next) => set({ [key]: next ?? 0 })}
        />
    );

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {mode === 'carrier' && (
                <>
                    <Field label="Phường/Xã" error={error('ward')}>
                        <Input
                            value={value.ward}
                            onChange={(event) =>
                                set({ ward: event.target.value })
                            }
                        />
                    </Field>
                    <Field label="Quận/Huyện" error={error('district')}>
                        <Input
                            value={value.district}
                            onChange={(event) =>
                                set({ district: event.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label="Tỉnh/Thành phố"
                        error={error('province')}
                        className="sm:col-span-2"
                    >
                        <Input
                            value={value.province}
                            onChange={(event) =>
                                set({ province: event.target.value })
                            }
                        />
                    </Field>
                </>
            )}
            {mode === 'external' && (
                <>
                    <Field
                        label="Đối tác vận chuyển"
                        error={error('carrier_id')}
                    >
                        <OptionSelect
                            value={
                                value.carrier_id
                                    ? String(value.carrier_id)
                                    : null
                            }
                            onChange={(next) =>
                                set({ carrier_id: next ? Number(next) : null })
                            }
                            options={toSelectOptions(options.partners ?? [])}
                            placeholder="Chọn đối tác"
                        />
                    </Field>
                    <Field label="Mã vận đơn" error={error('tracking_code')}>
                        <Input
                            value={value.tracking_code}
                            onChange={(event) =>
                                set({ tracking_code: event.target.value })
                            }
                        />
                    </Field>
                    <Field label="Phí trả đối tác" error={error('fee')}>
                        <MoneyInput
                            value={value.fee}
                            onValueChange={(next) => set({ fee: next })}
                        />
                    </Field>
                </>
            )}
            <Field label="Tiền thu hộ (COD)" error={error('cod_amount')}>
                <MoneyInput
                    value={value.cod_amount}
                    onValueChange={(next) => set({ cod_amount: next ?? 0 })}
                />
            </Field>
            <Field label="Khối lượng (g)" error={error('weight')}>
                <MoneyInput
                    value={value.weight}
                    onValueChange={(next) => set({ weight: next ?? 0 })}
                />
            </Field>
            <Field label="Kích thước (D x R x C, cm)" error={error('length')}>
                <div className="grid grid-cols-3 gap-2">
                    {size('length', 'Dài')}
                    {size('width', 'Rộng')}
                    {size('height', 'Cao')}
                </div>
            </Field>
            {mode === 'carrier' && (
                <Field
                    label="Khai giá hàng hóa"
                    error={error('insurance_value')}
                >
                    <MoneyInput
                        value={value.insurance_value}
                        placeholder="Không khai giá"
                        onValueChange={(next) => set({ insurance_value: next })}
                    />
                </Field>
            )}
            <Field label="Người trả phí" error={error('fee_payer')}>
                <OptionSelect
                    value={value.fee_payer}
                    onChange={(next) => set({ fee_payer: next ?? 'shop' })}
                    options={options.feePayers ?? []}
                />
            </Field>
            <Field label="Yêu cầu khi giao" error={error('required_note')}>
                <OptionSelect
                    value={value.required_note}
                    onChange={(next) =>
                        set({ required_note: next ?? 'CHOXEMHANGKHONGTHU' })
                    }
                    options={options.requiredNotes ?? []}
                />
            </Field>
            <Field
                label="Ghi chú giao hàng"
                error={error('note')}
                className="sm:col-span-2"
            >
                <Input
                    value={value.note}
                    onChange={(event) => set({ note: event.target.value })}
                />
            </Field>

            {mode === 'carrier' && (
                <div className="grid gap-2 sm:col-span-2">
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-sm font-medium">
                            Gói cước vận chuyển
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={loading}
                            onClick={() => void loadQuotes()}
                        >
                            {loading ? (
                                <Loader2 className="animate-spin" />
                            ) : (
                                <Truck />
                            )}
                            {quotes ? 'Tính lại phí' : 'Chọn gói cước'}
                        </Button>
                    </div>
                    {(quoteError ||
                        error('carrier') ||
                        error('carrier_id')) && (
                        <p className="text-sm text-destructive">
                            {quoteError ??
                                error('carrier') ??
                                error('carrier_id')}
                        </p>
                    )}
                    {(options.carriers ?? []).length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Chưa kết nối hãng vận chuyển nào. Vào Vận chuyển ›
                            Kết nối đối tác để kết nối GHN.
                        </p>
                    )}
                    {quotes && quotes.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Không có gói cước phù hợp.
                        </p>
                    )}
                    {quotes && quotes.length > 0 && (
                        <ul className="divide-y rounded-md border text-sm">
                            {quotes.map((quote) => {
                                const selected =
                                    quote.error === null &&
                                    value.carrier_id === quote.carrier_id &&
                                    value.service_id === quote.service_id;

                                return (
                                    <li
                                        key={`${quote.carrier_id}-${quote.service_id}`}
                                    >
                                        <button
                                            type="button"
                                            disabled={quote.error !== null}
                                            onClick={() =>
                                                set({
                                                    carrier_id:
                                                        quote.carrier_id,
                                                    service_id:
                                                        quote.service_id,
                                                    service_name:
                                                        quote.service_name,
                                                    fee: quote.fee,
                                                })
                                            }
                                            className={cn(
                                                'flex w-full items-center gap-3 px-3 py-2.5 text-left',
                                                selected && 'bg-accent',
                                                quote.error !== null &&
                                                    'cursor-not-allowed',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'size-4 shrink-0 rounded-full border',
                                                    selected &&
                                                        'border-4 border-primary',
                                                )}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="font-medium">
                                                    {quote.carrier}
                                                </span>
                                                {quote.service_name && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {quote.service_name}
                                                    </span>
                                                )}
                                                {quote.error ? (
                                                    <span className="block text-xs text-destructive">
                                                        {quote.error}
                                                    </span>
                                                ) : (
                                                    quote.expected_at && (
                                                        <span className="block text-xs text-muted-foreground">
                                                            Dự kiến giao{' '}
                                                            {formatDate(
                                                                quote.expected_at,
                                                            )}
                                                        </span>
                                                    )
                                                )}
                                            </span>
                                            {quote.fee !== null && (
                                                <span className="tabular-nums">
                                                    {formatNumber(quote.fee)} đ
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * "Street, ward, district, province" (or "street, district, province") as
 * the parts a carrier needs; the same split as the server's defaults.
 */
export function splitAddress(address: string) {
    const parts = address
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean);

    if (parts.length >= 4) {
        const [ward, district, province] = parts.slice(-3);

        return {
            street: parts.slice(0, -3).join(', '),
            ward,
            district,
            province,
        };
    }

    if (parts.length === 3) {
        return {
            street: parts[0],
            ward: '',
            district: parts[1],
            province: parts[2],
        };
    }

    return { street: address, ward: '', district: '', province: '' };
}

/** The shipment part of an order request (receiver from the order). */
export const shipmentPayload = (
    mode: 'carrier' | 'external',
    draft: ShipmentDraft,
    receiver: Receiver,
) => ({
    ...draft,
    ...receiver,
    fee: mode === 'external' ? draft.fee : null,
    tracking_code: mode === 'external' ? draft.tracking_code || null : null,
    service_id: mode === 'carrier' ? draft.service_id : null,
    service_name: mode === 'carrier' ? draft.service_name : null,
    ward: mode === 'carrier' ? draft.ward : null,
    district: mode === 'carrier' ? draft.district : null,
    province: mode === 'carrier' ? draft.province : null,
});

/**
 * "Giao hàng" on the order page: hand a packed or approved order to a
 * connected carrier or to a partner the store contacts itself.
 */
export function ShipmentDialog({
    open,
    onOpenChange,
    orderId,
    options,
    defaults,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    orderId: number;
    options: ShippingOptions;
    defaults: Receiver & Partial<ShipmentDraft>;
}) {
    const hasCarriers = (options.carriers ?? []).length > 0;
    const [mode, setMode] = useState<'carrier' | 'external'>(
        hasCarriers ? 'carrier' : 'external',
    );
    const [receiver, setReceiver] = useState<Receiver>({
        receiver_name: defaults.receiver_name,
        receiver_phone: defaults.receiver_phone,
        address: defaults.address,
    });
    const [draft, setDraft] = useState<ShipmentDraft>({
        ...emptyShipment(options, defaults.cod_amount ?? 0),
        ...defaults,
        carrier_id: null,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        router.post(
                            '/shipments',
                            {
                                order_id: orderId,
                                ...shipmentPayload(mode, draft, receiver),
                            },
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onError: (next) => setErrors(next),
                                onSuccess: () => {
                                    setErrors({});
                                    onOpenChange(false);
                                },
                            },
                        );
                    }}
                >
                    <div>
                        <DialogTitle>Đóng gói và giao hàng</DialogTitle>
                        <DialogDescription className="mt-1">
                            Tạo phiếu giao hàng cho đơn. Với hãng vận chuyển,
                            vận đơn được tạo ngay trên hệ thống của hãng.
                        </DialogDescription>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        {(
                            [
                                ['carrier', 'Đẩy qua hãng vận chuyển'],
                                ['external', 'Đẩy vận chuyển ngoài'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => {
                                    setMode(value);
                                    setDraft((previous) => ({
                                        ...previous,
                                        carrier_id: null,
                                        service_id: null,
                                        fee: null,
                                    }));
                                }}
                                className={cn(
                                    'rounded-md border px-3 py-2.5 text-left text-sm',
                                    mode === value
                                        ? 'border-primary bg-accent font-medium text-primary'
                                        : 'hover:border-primary/50',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Người nhận" error={errors.receiver_name}>
                            <Input
                                value={receiver.receiver_name}
                                onChange={(event) =>
                                    setReceiver({
                                        ...receiver,
                                        receiver_name: event.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            label="Số điện thoại"
                            error={errors.receiver_phone}
                        >
                            <Input
                                value={receiver.receiver_phone}
                                inputMode="tel"
                                onChange={(event) =>
                                    setReceiver({
                                        ...receiver,
                                        receiver_phone: event.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            label="Địa chỉ (số nhà, đường)"
                            error={errors.address}
                            className="sm:col-span-2"
                        >
                            <Input
                                value={receiver.address}
                                onChange={(event) =>
                                    setReceiver({
                                        ...receiver,
                                        address: event.target.value,
                                    })
                                }
                            />
                        </Field>
                    </div>
                    <ShipmentFields
                        mode={mode}
                        options={options}
                        value={draft}
                        onChange={setDraft}
                        receiver={receiver}
                        scope={{ order_id: orderId }}
                        errors={errors}
                    />
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Thoát
                        </Button>
                        <Button
                            disabled={
                                processing ||
                                !draft.carrier_id ||
                                (mode === 'carrier' && !draft.service_id)
                            }
                        >
                            Tạo phiếu giao hàng
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
