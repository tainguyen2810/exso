import { Head, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { DocumentLines, SummaryRow } from '@/components/app/document-lines';
import type { LineColumn, LineProduct } from '@/components/app/document-lines';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { SupplierPicker } from '@/components/app/supplier-picker';
import type { SupplierOption } from '@/components/app/supplier-picker';
import type { VariantOption } from '@/components/app/variant-picker';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatNumber } from '@/lib/format';

export type PurchaseLine = LineProduct & {
    quantity: number;
    price: number;
    discount: number;
    on_hand?: number | null;
};

export type Cost = { name: string; amount: number };

export type PurchaseDraft = {
    id?: number;
    code?: string;
    supplier: SupplierOption | null;
    branch_id: number;
    purchase_order_id?: number | null;
    purchase_order_code?: string | null;
    expected_at?: string | null;
    discount: number;
    costs?: Cost[];
    note: string | null;
    reference?: string | null;
    items: PurchaseLine[];
};

export type Branch = { id: number; name: string };

const lineTotal = (line: PurchaseLine) =>
    Math.max(0, line.quantity * line.price - line.discount);

/** "2026-11-05" → "05/11/2026"; the server accepts both. */
const toDisplayDate = (value: string | null | undefined) =>
    value ? value.split('-').reverse().join('/') : '';

/**
 * Create / edit form shared by "Đặt hàng nhập" (order) and "Nhập hàng"
 * (receipt), laid out like Sapo: supplier and products on the left, branch
 * and notes on the right, totals with discount (F6) and costs (F7) below.
 * The server recalculates every total.
 */
export function PurchaseForm({
    kind,
    draft,
    branches,
    currentBranchId,
}: {
    kind: 'order' | 'receipt';
    draft: PurchaseDraft | null;
    branches: Branch[];
    currentBranchId: number | null;
}) {
    const can = useCan();
    const receipt = kind === 'receipt';
    const editing = draft?.id !== undefined;
    const [supplier, setSupplier] = useState<SupplierOption | null>(
        draft?.supplier ?? null,
    );
    const form = useForm({
        supplier_id: draft?.supplier?.id ?? null,
        branch_id: draft?.branch_id ?? currentBranchId ?? branches[0]?.id,
        purchase_order_id: draft?.purchase_order_id ?? null,
        expected_at: toDisplayDate(draft?.expected_at),
        discount: draft?.discount ?? 0,
        costs: draft?.costs ?? [],
        note: draft?.note ?? '',
        reference: draft?.reference ?? '',
        items: draft?.items ?? [],
        receive: false,
    });
    const discountInput = useRef<HTMLInputElement>(null);
    const costInput = useRef<HTMLInputElement>(null);
    const [focusCost, setFocusCost] = useState(false);
    const errors = form.errors as Record<string, string | undefined>;

    const subtotal = form.data.items.reduce(
        (sum, line) => sum + lineTotal(line),
        0,
    );
    const discount = Math.min(form.data.discount, subtotal);
    const costTotal = form.data.costs.reduce(
        (sum, cost) => sum + cost.amount,
        0,
    );
    const total = subtotal - discount + (receipt ? costTotal : 0);

    const addCost = () => {
        form.setData('costs', [...form.data.costs, { name: '', amount: 0 }]);
        setFocusCost(true);
    };

    useEffect(() => {
        if (focusCost) {
            costInput.current?.focus();
            setFocusCost(false);
        }
    }, [focusCost]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F6') {
                event.preventDefault();
                discountInput.current?.focus();
            } else if (event.key === 'F7' && receipt) {
                event.preventDefault();
                form.setData((data) => ({
                    ...data,
                    costs: [...data.costs, { name: '', amount: 0 }],
                }));
                setFocusCost(true);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
        // form.setData is stable for the page's lifetime.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [receipt]);

    const setLine = (index: number, patch: Partial<PurchaseLine>) =>
        form.setData(
            'items',
            form.data.items.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );

    const pick = (variant: VariantOption) => {
        const index = form.data.items.findIndex(
            (line) => line.variant_id === variant.id,
        );

        if (index >= 0) {
            setLine(index, {
                quantity: form.data.items[index].quantity + 1,
            });

            return;
        }

        form.setData('items', [
            ...form.data.items,
            {
                variant_id: variant.id,
                product_id: variant.product_id,
                name: variant.name,
                sku: variant.sku,
                unit: variant.unit,
                image_url: variant.image_url,
                on_hand: variant.on_hand,
                quantity: 1,
                price: variant.import_price ?? 0,
                discount: 0,
            },
        ]);
    };

    const submit = (receive: boolean) => {
        form.transform((data) => ({
            ...data,
            receive,
            items: data.items.map((line) => ({
                variant_id: line.variant_id,
                quantity: line.quantity,
                price: line.price,
                discount: line.discount,
            })),
            costs: receipt
                ? data.costs.filter(
                      (cost) => cost.name.trim() !== '' || cost.amount > 0,
                  )
                : [],
        }));

        const base = receipt ? '/purchase-receipts' : '/purchase-orders';

        if (editing) {
            form.put(`${base}/${draft?.id}`, { preserveScroll: true });
        } else {
            form.post(base, { preserveScroll: true });
        }
    };

    const columns: LineColumn<PurchaseLine>[] = [
        {
            key: 'on_hand',
            header: 'Tồn kho',
            className: 'text-right tabular-nums text-muted-foreground',
            cell: (line) =>
                line.on_hand === null || line.on_hand === undefined
                    ? ''
                    : formatNumber(line.on_hand),
        },
        {
            key: 'quantity',
            header: 'Số lượng',
            className: 'w-28',
            cell: (line, index) => (
                <MoneyInput
                    aria-label="Số lượng"
                    value={line.quantity}
                    aria-invalid={!!errors[`items.${index}.quantity`]}
                    onValueChange={(value) =>
                        setLine(index, { quantity: value ?? 0 })
                    }
                />
            ),
        },
        {
            key: 'price',
            header: 'Đơn giá',
            className: 'w-36',
            cell: (line, index) => (
                <MoneyInput
                    aria-label="Đơn giá"
                    value={line.price}
                    aria-invalid={!!errors[`items.${index}.price`]}
                    onValueChange={(value) =>
                        setLine(index, { price: value ?? 0 })
                    }
                />
            ),
        },
        {
            key: 'discount',
            header: 'Chiết khấu',
            className: 'w-32',
            cell: (line, index) => (
                <MoneyInput
                    aria-label="Chiết khấu"
                    value={line.discount}
                    onValueChange={(value) =>
                        setLine(index, { discount: value ?? 0 })
                    }
                />
            ),
        },
        {
            key: 'total',
            header: 'Thành tiền',
            className: 'text-right tabular-nums pr-5',
            cell: (line) => formatNumber(lineTotal(line)),
        },
    ];

    const title = editing
        ? `${receipt ? 'Sửa phiếu nhập' : 'Sửa đơn đặt hàng'} ${draft?.code}`
        : receipt
          ? 'Tạo phiếu nhập hàng'
          : 'Tạo đơn đặt hàng nhập';
    const list = receipt ? '/purchase-receipts' : '/purchase-orders';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref={editing ? `${list}/${draft?.id}` : list}
                backLabel={
                    editing
                        ? 'Quay lại chi tiết'
                        : receipt
                          ? 'Quay lại danh sách phiếu nhập'
                          : 'Quay lại danh sách đặt hàng nhập'
                }
                onSubmit={() => submit(false)}
                processing={form.processing}
                submitLabel={receipt ? 'Lưu nháp' : 'Lưu'}
                submitVariant={
                    receipt && can('purchases.approve') ? 'outline' : 'default'
                }
                extraActions={
                    receipt &&
                    can('purchases.approve') && (
                        <Button
                            type="button"
                            disabled={form.processing}
                            onClick={() => submit(true)}
                        >
                            Nhập kho
                        </Button>
                    )
                }
                aside={
                    <>
                        <FormSection title="Thông tin phiếu">
                            <Field
                                label="Chi nhánh nhập"
                                error={form.errors.branch_id}
                            >
                                <Select
                                    value={String(form.data.branch_id ?? '')}
                                    onValueChange={(value) =>
                                        form.setData('branch_id', Number(value))
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Chọn chi nhánh" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {branches.map((branch) => (
                                            <SelectItem
                                                key={branch.id}
                                                value={String(branch.id)}
                                            >
                                                {branch.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            {draft?.purchase_order_code && (
                                <SummaryRow
                                    label="Đơn đặt hàng"
                                    value={draft.purchase_order_code}
                                />
                            )}
                            {receipt ? (
                                <Field
                                    label="Mã tham chiếu"
                                    error={form.errors.reference}
                                >
                                    <Input
                                        value={form.data.reference}
                                        placeholder="Số hóa đơn của nhà cung cấp"
                                        onChange={(event) =>
                                            form.setData(
                                                'reference',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            ) : (
                                <Field
                                    label="Ngày nhận dự kiến"
                                    error={form.errors.expected_at}
                                >
                                    <Input
                                        value={form.data.expected_at}
                                        placeholder="dd/mm/yyyy"
                                        onChange={(event) =>
                                            form.setData(
                                                'expected_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                        </FormSection>
                        <FormSection title="Ghi chú">
                            <Textarea
                                aria-label="Ghi chú"
                                value={form.data.note}
                                placeholder="VD: Hàng giao trong giờ hành chính"
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                            />
                        </FormSection>
                    </>
                }
            >
                <FormSection title="Nhà cung cấp">
                    <SupplierPicker
                        value={supplier}
                        error={form.errors.supplier_id}
                        onChange={(value) => {
                            setSupplier(value);
                            form.setData('supplier_id', value?.id ?? null);
                        }}
                    />
                </FormSection>
                <DocumentLines
                    lines={form.data.items}
                    columns={columns}
                    types={['normal', 'service']}
                    branchId={form.data.branch_id}
                    price="import_price"
                    onPick={pick}
                    onRemove={(index) =>
                        form.setData(
                            'items',
                            form.data.items.filter((_, i) => i !== index),
                        )
                    }
                    error={form.errors.items ?? errors.stock}
                />
                <Card className="gap-0 py-0">
                    <div className="grid gap-3 px-5 py-4 md:ml-auto md:w-96">
                        <SummaryRow
                            label={`Tổng tiền hàng (${form.data.items.length} sản phẩm)`}
                            value={formatNumber(subtotal)}
                        />
                        <SummaryRow label="Chiết khấu đơn (F6)">
                            <MoneyInput
                                ref={discountInput}
                                aria-label="Chiết khấu đơn"
                                className="h-8 w-36"
                                value={form.data.discount}
                                onValueChange={(value) =>
                                    form.setData('discount', value ?? 0)
                                }
                            />
                        </SummaryRow>
                        {receipt && (
                            <div className="grid gap-2">
                                <SummaryRow label="Chi phí nhập hàng (F7)">
                                    <Button
                                        type="button"
                                        variant="link"
                                        className="h-auto p-0"
                                        onClick={addCost}
                                    >
                                        <Plus /> Thêm chi phí
                                    </Button>
                                </SummaryRow>
                                {form.data.costs.map((cost, index) => (
                                    <div
                                        key={index}
                                        className="flex items-center gap-2"
                                    >
                                        <Input
                                            ref={
                                                index ===
                                                form.data.costs.length - 1
                                                    ? costInput
                                                    : undefined
                                            }
                                            className="h-8"
                                            placeholder="Tên chi phí, VD: Vận chuyển"
                                            aria-invalid={
                                                !!errors[`costs.${index}.name`]
                                            }
                                            value={cost.name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'costs',
                                                    form.data.costs.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      name: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <MoneyInput
                                            aria-label="Số tiền chi phí"
                                            className="h-8 w-32"
                                            value={cost.amount}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'costs',
                                                    form.data.costs.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      amount:
                                                                          value ??
                                                                          0,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground"
                                            aria-label="Bỏ chi phí"
                                            onClick={() =>
                                                form.setData(
                                                    'costs',
                                                    form.data.costs.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                ))}
                                {costTotal > 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        Chi phí và chiết khấu đơn được phân bổ
                                        vào giá vốn theo giá trị từng dòng.
                                    </p>
                                )}
                            </div>
                        )}
                        <div className="border-t pt-3">
                            <SummaryRow
                                strong
                                label="Tiền cần trả"
                                value={formatNumber(total)}
                            />
                        </div>
                    </div>
                </Card>
            </DocumentForm>
        </>
    );
}
