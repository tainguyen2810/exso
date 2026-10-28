import { Head, useForm } from '@inertiajs/react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { DocumentLines, SummaryRow } from '@/components/app/document-lines';
import type { LineColumn, LineProduct } from '@/components/app/document-lines';
import { DocumentView } from '@/components/app/document-view';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { PageErrors } from '@/components/app/page-errors';
import { StatusPill } from '@/components/app/status-badge';
import type { VariantOption } from '@/components/app/variant-picker';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { adjustmentStatuses } from '../../components/statuses';

type Line = LineProduct & {
    old_cost: number | null;
    new_cost: number | null;
};

type Adjustment = {
    id: number;
    code: string;
    status: string;
    branch_id: number;
    note: string | null;
    creator: string | null;
    applier: string | null;
    created_at: string | null;
    applied_at: string | null;
    items: Line[];
};

type Branch = { id: number; name: string };

/**
 * "Điều chỉnh giá vốn": set a new average cost for products, e.g. after
 * a wrong purchase price. Needs the cost permission.
 */
export default function CostAdjustmentForm({
    adjustment,
    branches,
    currentBranchId,
}: {
    adjustment: Adjustment | null;
    branches: Branch[];
    currentBranchId: number | null;
}) {
    const can = useCan();
    const editable =
        adjustment === null
            ? can('inventory.create')
            : adjustment.status === 'draft' && can('inventory.update');
    const form = useForm({
        branch_id: adjustment?.branch_id ?? currentBranchId ?? branches[0]?.id,
        note: adjustment?.note ?? '',
        items: adjustment?.items ?? [],
        apply: false,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const lines = form.data.items;

    const setLine = (index: number, patch: Partial<Line>) =>
        form.setData(
            'items',
            lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );

    const pick = (variant: VariantOption) => {
        if (lines.some((line) => line.variant_id === variant.id)) {
            return;
        }

        form.setData('items', [
            ...lines,
            {
                variant_id: variant.id,
                product_id: variant.product_id,
                name: variant.name,
                sku: variant.sku,
                unit: variant.unit,
                image_url: variant.image_url,
                old_cost: variant.cost_price ?? 0,
                new_cost: variant.cost_price ?? 0,
            },
        ]);
    };

    const submit = (apply: boolean) => {
        form.transform((data) => ({
            ...data,
            apply,
            items: data.items.map((line) => ({
                variant_id: line.variant_id,
                new_cost: line.new_cost,
            })),
        }));

        if (adjustment) {
            form.put(`/cost-adjustments/${adjustment.id}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/cost-adjustments', { preserveScroll: true });
        }
    };

    const columns: LineColumn<Line>[] = [
        {
            key: 'old_cost',
            header: 'Giá vốn hiện tại',
            className: 'text-right tabular-nums',
            cell: (line) => formatNumber(line.old_cost ?? 0),
        },
        {
            key: 'new_cost',
            header: 'Giá vốn mới',
            className: editable ? 'w-36' : 'text-right tabular-nums',
            cell: (line, index) =>
                editable ? (
                    <MoneyInput
                        aria-label={`Giá vốn mới ${line.name}`}
                        aria-invalid={!!errors[`items.${index}.new_cost`]}
                        value={line.new_cost}
                        onValueChange={(value) =>
                            setLine(index, { new_cost: value })
                        }
                    />
                ) : (
                    formatNumber(line.new_cost ?? 0)
                ),
        },
        {
            key: 'difference',
            header: 'Chênh lệch',
            className: 'pr-5 text-right tabular-nums',
            cell: (line) => {
                const diff = (line.new_cost ?? 0) - (line.old_cost ?? 0);

                return (
                    <span
                        className={cn(
                            diff > 0 && 'text-success',
                            diff < 0 && 'text-destructive',
                        )}
                    >
                        {diff > 0 ? '+' : ''}
                        {formatNumber(diff)}
                    </span>
                );
            },
        },
    ];

    const linesCard = (
        <DocumentLines
            lines={lines}
            columns={columns}
            readOnly={!editable}
            baseOnly
            price="cost_price"
            onPick={pick}
            onRemove={(index) =>
                form.setData(
                    'items',
                    lines.filter((_, i) => i !== index),
                )
            }
            error={form.errors.items}
        />
    );

    if (!editable && adjustment) {
        return (
            <>
                <Head title={`Phiếu điều chỉnh ${adjustment.code}`} />
                <DocumentView
                    title={adjustment.code}
                    badges={
                        <StatusPill
                            statuses={adjustmentStatuses}
                            value={adjustment.status}
                        />
                    }
                    backHref="/cost-adjustments"
                    backLabel="Quay lại danh sách phiếu điều chỉnh"
                    aside={
                        <FormSection title="Thông tin phiếu" className="gap-2">
                            <SummaryRow
                                label="Ngày tạo"
                                value={formatDateTime(adjustment.created_at)}
                            />
                            <SummaryRow
                                label="Người tạo"
                                value={adjustment.creator ?? '—'}
                            />
                            {adjustment.applied_at && (
                                <SummaryRow
                                    label="Ngày điều chỉnh"
                                    value={`${formatDateTime(adjustment.applied_at)}${adjustment.applier ? ` · ${adjustment.applier}` : ''}`}
                                />
                            )}
                            {adjustment.note && (
                                <p className="pt-2 text-sm whitespace-pre-line text-muted-foreground">
                                    {adjustment.note}
                                </p>
                            )}
                        </FormSection>
                    }
                >
                    {linesCard}
                </DocumentView>
            </>
        );
    }

    const title = adjustment ? adjustment.code : 'Tạo phiếu điều chỉnh giá vốn';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/cost-adjustments"
                backLabel="Quay lại danh sách phiếu điều chỉnh"
                onSubmit={() => submit(false)}
                processing={form.processing}
                submitVariant={can('inventory.approve') ? 'outline' : 'default'}
                extraActions={
                    can('inventory.approve') && (
                        <ConfirmAction
                            title="Điều chỉnh giá vốn?"
                            description={`Giá vốn của ${lines.length} sản phẩm sẽ được đổi sang giá mới, áp dụng cho các giao dịch từ bây giờ.`}
                            confirmLabel="Điều chỉnh"
                            onConfirm={() => submit(true)}
                            trigger={
                                <Button
                                    type="button"
                                    disabled={
                                        form.processing || lines.length === 0
                                    }
                                >
                                    Điều chỉnh
                                </Button>
                            }
                        />
                    )
                }
                actions={
                    adjustment &&
                    can('inventory.delete') && (
                        <ConfirmAction
                            url={`/cost-adjustments/${adjustment.id}/cancel`}
                            title={`Hủy phiếu ${adjustment.code}?`}
                            description="Phiếu chưa điều chỉnh nên giá vốn không thay đổi."
                            confirmLabel="Hủy phiếu"
                            destructive
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Hủy phiếu
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <>
                        <FormSection title="Thông tin phiếu">
                            <Field
                                label="Chi nhánh"
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
                            <p className="text-xs text-muted-foreground">
                                Giá vốn là bình quân trên mọi chi nhánh; chi
                                nhánh chỉ để ghi nhận nơi lập phiếu.
                            </p>
                        </FormSection>
                        <FormSection title="Ghi chú">
                            <Textarea
                                aria-label="Ghi chú"
                                placeholder="Lý do điều chỉnh"
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                            />
                        </FormSection>
                    </>
                }
            >
                <PageErrors />
                {linesCard}
            </DocumentForm>
        </>
    );
}

CostAdjustmentForm.layout = {
    breadcrumbs: [
        { title: 'Điều chỉnh giá vốn', href: '/cost-adjustments' },
        { title: 'Phiếu điều chỉnh', href: '#' },
    ],
};
