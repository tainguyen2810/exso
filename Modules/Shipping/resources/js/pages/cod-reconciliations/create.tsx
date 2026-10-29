import { Head, useForm } from '@inertiajs/react';
import { ListPlus, Search, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { PageErrors } from '@/components/app/page-errors';
import { StatusPill } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { shipmentStatuses } from '../../components/statuses';

type Option = { id: number; name: string };

type Found = {
    id: number;
    code: string;
    tracking_code: string | null;
    order_code: string;
    status: string;
    receiver_name: string;
    cod: number;
    fee: number;
};

type Item = Found & { partner_cod: number; partner_fee: number };

async function findShipments(
    params: Record<string, string | number | boolean>,
) {
    const query = new URLSearchParams(
        Object.entries(params).map(([key, value]) => [key, String(value)]),
    );
    const response = await fetch(`/shipments/search?${query}`, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    return response.ok ? ((await response.json()) as Found[]) : [];
}

/**
 * "Tạo phiếu đối soát" like Sapo: partner and branch, then the finished
 * shipments (F3, or "Chọn nhiều" for all of them) with the partner's COD
 * and fee next to the system's.
 */
export default function CodReconciliationCreate({
    carriers,
    branches,
    currentBranchId,
    presetCarrierId,
}: {
    carriers: (Option & { kind: string })[];
    branches: Option[];
    currentBranchId: number | null;
    presetCarrierId: number | null;
}) {
    const form = useForm({
        carrier_id: (presetCarrierId ?? carriers[0]?.id ?? null) as
            | number
            | null,
        branch_id: (currentBranchId ?? branches[0]?.id ?? null) as
            | number
            | null,
        note: '',
        tags: '',
        items: [] as Item[],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<Found[]>([]);
    const [open, setOpen] = useState(false);
    const searchInput = useRef<HTMLInputElement>(null);
    const scope = useCallback(
        () => ({
            carrier_id: form.data.carrier_id ?? 0,
            branch_id: form.data.branch_id ?? 0,
        }),
        [form.data.carrier_id, form.data.branch_id],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = setTimeout(() => {
            void findShipments({ ...scope(), q: term }).then(setResults);
        }, 250);

        return () => clearTimeout(timer);
    }, [term, open, scope]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F3') {
                event.preventDefault();
                searchInput.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const add = (found: Found[]) => {
        const known = new Set(form.data.items.map((item) => item.id));
        form.setData('items', [
            ...form.data.items,
            ...found
                .filter((item) => !known.has(item.id))
                .map((item) => ({
                    ...item,
                    partner_cod: item.cod,
                    partner_fee: item.fee,
                })),
        ]);
    };
    const setItem = (id: number, patch: Partial<Item>) =>
        form.setData(
            'items',
            form.data.items.map((item) =>
                item.id === id ? { ...item, ...patch } : item,
            ),
        );
    const changeScope = (patch: {
        carrier_id?: number | null;
        branch_id?: number | null;
    }) => form.setData((previous) => ({ ...previous, ...patch, items: [] }));
    const sum = (key: 'cod' | 'fee' | 'partner_cod' | 'partner_fee') =>
        form.data.items.reduce((total, item) => total + item[key], 0);

    return (
        <>
            <Head title="Tạo phiếu đối soát" />
            <DocumentForm
                title="Tạo phiếu đối soát"
                backHref="/cod-reconciliations"
                backLabel="Quay lại danh sách phiếu đối soát"
                processing={form.processing}
                onSubmit={() => {
                    form.transform((values) => ({
                        ...values,
                        items: values.items.map((item) => ({
                            shipment_id: item.id,
                            partner_cod: item.partner_cod,
                            partner_fee: item.partner_fee,
                        })),
                    }));
                    form.post('/cod-reconciliations');
                }}
                aside={
                    <>
                        <FormSection title="Thông tin bổ sung">
                            <Field label="Ghi chú" error={errors.note}>
                                <Textarea
                                    value={form.data.note}
                                    onChange={(event) =>
                                        form.setData('note', event.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Tags" error={errors.tags}>
                                <Input
                                    value={form.data.tags}
                                    onChange={(event) =>
                                        form.setData('tags', event.target.value)
                                    }
                                />
                            </Field>
                        </FormSection>
                        <FormSection title="Tổng đối soát">
                            <div className="grid gap-1.5 text-sm">
                                <Sum
                                    label="Số vận đơn"
                                    value={form.data.items.length}
                                />
                                <Sum label="COD hệ thống" value={sum('cod')} />
                                <Sum
                                    label="Phí GH hệ thống"
                                    value={sum('fee')}
                                />
                                <Sum
                                    label="COD đối tác"
                                    value={sum('partner_cod')}
                                />
                                <Sum
                                    label="Phí GH đối tác"
                                    value={sum('partner_fee')}
                                />
                                <Sum
                                    label="Đối tác phải trả"
                                    value={
                                        sum('partner_cod') - sum('partner_fee')
                                    }
                                    strong
                                />
                            </div>
                        </FormSection>
                    </>
                }
            >
                <PageErrors />
                <FormSection title="Thông tin đối soát">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Đối tác vận chuyển"
                            error={errors.carrier_id ?? errors.carrier}
                        >
                            <OptionSelect
                                value={
                                    form.data.carrier_id
                                        ? String(form.data.carrier_id)
                                        : null
                                }
                                onChange={(value) =>
                                    changeScope({
                                        carrier_id: value
                                            ? Number(value)
                                            : null,
                                    })
                                }
                                options={toSelectOptions(carriers)}
                                placeholder="Chọn đối tác"
                            />
                        </Field>
                        <Field label="Chi nhánh" error={errors.branch_id}>
                            <OptionSelect
                                value={
                                    form.data.branch_id
                                        ? String(form.data.branch_id)
                                        : null
                                }
                                onChange={(value) =>
                                    changeScope({
                                        branch_id: value ? Number(value) : null,
                                    })
                                }
                                options={toSelectOptions(branches)}
                            />
                        </Field>
                    </div>
                </FormSection>
                <FormSection title="Vận đơn">
                    <div className="flex gap-2">
                        <div className="relative flex-1">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                ref={searchInput}
                                className="pl-9"
                                placeholder="Tìm theo mã vận đơn, mã đơn hàng, người nhận (F3)"
                                value={term}
                                onFocus={() => setOpen(true)}
                                onBlur={() =>
                                    setTimeout(() => setOpen(false), 200)
                                }
                                onChange={(event) =>
                                    setTerm(event.target.value)
                                }
                            />
                            {open && results.length > 0 && (
                                <ul className="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-popover text-sm shadow-md">
                                    {results.map((item) => (
                                        <li key={item.id}>
                                            <button
                                                type="button"
                                                className="flex w-full justify-between gap-3 px-3 py-2 text-left hover:bg-accent"
                                                onMouseDown={(event) =>
                                                    event.preventDefault()
                                                }
                                                onClick={() => add([item])}
                                            >
                                                <span>
                                                    {item.tracking_code ??
                                                        item.code}
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {
                                                            item.order_code
                                                        } ·{' '}
                                                        {item.receiver_name}
                                                    </span>
                                                </span>
                                                <span className="tabular-nums">
                                                    COD {formatNumber(item.cod)}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                void findShipments({ ...scope(), all: 1 }).then(
                                    add,
                                )
                            }
                        >
                            <ListPlus /> Chọn nhiều
                        </Button>
                    </div>
                    {errors.items && (
                        <p className="text-sm text-destructive">
                            {errors.items}
                        </p>
                    )}
                    {form.data.items.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            Chọn vận đơn đã giao hoặc đã hoàn để đối soát. "Chọn
                            nhiều" thêm tất cả vận đơn chờ đối soát của đối tác
                            ở chi nhánh này.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-muted-foreground">
                                    <tr className="border-b">
                                        <th className="py-2 font-normal">
                                            Vận đơn
                                        </th>
                                        <th className="py-2 text-right font-normal">
                                            COD hệ thống
                                        </th>
                                        <th className="py-2 text-right font-normal">
                                            Phí hệ thống
                                        </th>
                                        <th className="w-32 py-2 text-right font-normal">
                                            COD đối tác
                                        </th>
                                        <th className="w-32 py-2 text-right font-normal">
                                            Phí đối tác
                                        </th>
                                        <th className="w-8" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {form.data.items.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2">
                                                <div>
                                                    {item.tracking_code ??
                                                        item.code}
                                                </div>
                                                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    {item.order_code}
                                                    <StatusPill
                                                        statuses={
                                                            shipmentStatuses
                                                        }
                                                        value={item.status}
                                                    />
                                                </div>
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {formatNumber(item.cod)}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {formatNumber(item.fee)}
                                            </td>
                                            <td className="py-2 pl-2">
                                                <MoneyInput
                                                    className={cn(
                                                        item.partner_cod !==
                                                            item.cod &&
                                                            'text-destructive',
                                                    )}
                                                    value={item.partner_cod}
                                                    onValueChange={(value) =>
                                                        setItem(item.id, {
                                                            partner_cod:
                                                                value ?? 0,
                                                        })
                                                    }
                                                />
                                            </td>
                                            <td className="py-2 pl-2">
                                                <MoneyInput
                                                    className={cn(
                                                        item.partner_fee !==
                                                            item.fee &&
                                                            'text-destructive',
                                                    )}
                                                    value={item.partner_fee}
                                                    onValueChange={(value) =>
                                                        setItem(item.id, {
                                                            partner_fee:
                                                                value ?? 0,
                                                        })
                                                    }
                                                />
                                            </td>
                                            <td className="py-2 text-right">
                                                <button
                                                    type="button"
                                                    aria-label="Bỏ vận đơn"
                                                    className="text-muted-foreground hover:text-destructive"
                                                    onClick={() =>
                                                        form.setData(
                                                            'items',
                                                            form.data.items.filter(
                                                                (row) =>
                                                                    row.id !==
                                                                    item.id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <X className="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </FormSection>
            </DocumentForm>
        </>
    );
}

function Sum({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: number;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex justify-between gap-3',
                strong && 'font-semibold',
            )}
        >
            <span>{label}</span>
            <span className="tabular-nums">{formatNumber(value)}</span>
        </div>
    );
}

CodReconciliationCreate.layout = {
    breadcrumbs: [
        { title: 'Đối soát COD và phí', href: '/cod-reconciliations' },
        { title: 'Tạo phiếu đối soát', href: '/cod-reconciliations/create' },
    ],
};
